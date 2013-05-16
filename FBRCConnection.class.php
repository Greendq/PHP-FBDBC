<?php

// Firebird Redis Cached Connection Class
// 
// Author:
//          Sergey Mereutsa <serj@dqteam.com>
// 
// Permission is hereby granted, free of charge, to any person obtaining
// a copy of this software and associated documentation files (the
// "Software"), to deal in the Software without restriction, including
// without limitation the rights to use, copy, modify, merge, publish,
// distribute, sublicense, and/or sell copies of the Software, and to
// permit persons to whom the Software is furnished to do so, subject to
// the following conditions:
// 
// The above copyright notice and this permission notice shall be
// included in all copies or substantial portions of the Software.
// 
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
// EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
// NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
// LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
// OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
// WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

/*
  This class is a wrapper for FB-specific classes
  For lazzy programmers :-)
  It uses 2 transactions to access to database:
  1) Read-only lifetime transaction (lifetime == class lifetime)
  2) Read-write short transaction (lifetime == function call lifetime)
  @Note:
  When we start RW transaction we can use dummy insert/delete in lock table -
  usually ts_lock to help syncronize database write operations for processes
  interested in such kind of syncronization (invented for DQH game).
  Use extended class for lock implementation

  @Note:

  This class uses Redis memory-cache server to cache results for RO-queries.

 */

require_once("FBDatabase.class.php");
require_once("FBTransaction.class.php");
require_once("FBQuery.class.php");
require_once("FBConnection.class.php");
require_once __DIR__ . '/../predis/Predis.php';

class FBRCConnection extends FBConnection
{

    private $redisData = null;
    private $redisClient = null;
    private $redisCacheTTL = 600; //! default TTL for RedisCache
    private $redisCacheCompressionLevel = 3; // compress results a bit
    // used for delayed connection to database
    private $isConnected = false;
    protected $dbUser;
    protected $dbPassword;
    protected $dbRole;
    protected $dbName;
    protected $dbCharset;
    protected $dbPages;
    protected $usePool;

    // clear (invalidate) Redis cache for the given key(s)
    // Note: if you do not pass key prefix or pass '*'
    // it will fluch current database
    public function ClearCache($keyPrefix = '*')
    {
        if (!$this->redisClient)
        {
            return; // we can not clear cache untill we connect it
        }

        error_log('Going to clear cache....', 0);
        // special case - flush database
        if ('*' == $keyPrefix)
        {
            $this->redisClient->flushdb();
        } else
        {
            // getting keys list
            $r = $this->redisClient->keys($keyPrefix);
            //print_r($r);
            foreach ($r as $key)
            {
                $this->redisClient->expire($key, 1); // expire the key in 1 second
            }
        }
    }

    public function FBRCConnection()
    {
        parent::__construct();
        $this->isConnected = false;
    }

    /*
     * Actually, we do NOT connect to the database on the first function call
     * We really connect to the database on RW transaction OR if query resultset is not   
     * found in the Redis DB cache
     * */

    public function Connect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset = "utf8", $dbPages = 10000, $usePool = false)
    {
        $this->dbUser = $dbUser;
        $this->dbPassword = $dbPassword;
        $this->dbRole = $dbRole;
        $this->dbName = $dbName;
        $this->dbCharset = $dbCharset;
        $this->dbPages = $dbPages;
        $this->usePool = $usePool;
    }

    /*
     * Here we really connecting to database
     * 
     * */

    public function RealConnect()
    {
        parent::Connect($this->dbUser, $this->dbPassword, $this->dbRole, $this->dbName, $this->dbCharset, $this->dbPages, $this->usePool);

        $this->isConnected = true;
    }

    // sets info about Redis DB connection and connects to redis DB
    public function SetRedisCache($host = 'localhost', $port = '6379', $database = 15, $rCTTL = 300)
    {
        $this->redisData = array();
        $this->redisData['host'] = $host;
        $this->redisData['port'] = $port;
        $this->redisData['database'] = $database;
        $this->redisCacheTTL = $rCTTL;

        // connection to Redis server
        $this->redisClient = new Predis\Client($this->redisData);
    }

    // get cached result from cache (if any), uncompress, unserialize and return it
    public function GetCachedResultSet($sqlText, $params = array())
    {
        if (!is_array($params))
        {
            $paramsHash = sha1($params);
        } else
        {
            $paramsHash = sha1(implode(',', $params));
        }

        $r = $this->redisClient->get(sha1($sqlText) . ':' . $paramsHash . ':' . $this->redisCacheCompressionLevel);

        if ($r)
        {
            $r = unserialize(gzuncompress($r));
            return $r;
        }
        return null; // cache miss
    }

    // serialize, compress it and store in the cache
    public function SetCachedResultSet($resultSet, $sqlText, $params = array(), $customTTL = -1)
    {
        if (!is_array($params))
        {
            $paramsHash = sha1($params);
        } else
        {
            $paramsHash = sha1(implode(',', $params));
        }
        // serialize and compress resultset
        $safeResultset = gzcompress(serialize($resultSet), $this->redisCacheCompressionLevel);
        $key = sha1($sqlText) . ':' . $paramsHash . ':' . $this->redisCacheCompressionLevel;
        $this->redisClient->set($key, $safeResultset);

        if ($customTTL != - 1)
        {
            $this->redisClient->expire($key, $customTTL);
        } else
        {
            $this->redisClient->expire($key, $this->redisCacheTTL);
        }
        $key = null;
        $safeResultset = null; // help to GC :)
    }

    /**
     * The same story as for parent`s method with the same name,
     * but we can return cached data if it is RO transaction
     * and we will store RO results in the cache, in case of cache miss
     *
     * GetAllRows - Get resultset as 2D hash array.
     *
     * Will fetch blobs, so avoid to do select * on large data tables!
     *
     * SQL cursor will be closed after fetch
     *
     * When using RW transaction, transaction will be started/commited immediatelly
     * after query ONLY if it was not started before
     *
     * @param string      $sqlText - SQL text with placeholders (?)
     * @param array       $params  - query input parameters
     * @param bool        $RW      - RW transaction (true) or RO transaction(false)
     * @param bool		  $USELOCK - should we call lock() method or not 	
     * @return array	  $result	- result set, may be cached if RO transaction
     */
    public function GetAllRows($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        if (!$RW) // we can not cache RW queries
        {
            // check cache hit - if the resultset is in the cache - just return it
            $result = $this->GetCachedResultSet($sqlText, $params);

            if ($result != null)
            {
                return $result;
            }
        }
        if (!$this->isConnected)
        {
            $this->RealConnect();
        }

        $result = parent::GetAllRows($sqlText, $params, $RW, $USELOCK);

        if (!$RW)
        {
            // cache the result
            $this->SetCachedResultSet($result, $sqlText, $params);
        }
        return $result;
    }

    // return one row from resultset as 1D hash array
    /**
     * The same story as for parent`s method with the same name,
     * but we can return cached data if it is RO transaction
     * and we will store RO results in the cache, in case of cache miss

     * GetOneRow - Get resultset as 1D hash array.
     *
     * Will fetch blobs, so avoid to do select * on large data tables!
     *
     * SQL cursor will be closed after fetch
     *
     * When using RW transaction, transaction will be started/commited immediatelly
     * after query ONLY if it was not started before
     *
     * @param string      $sqlText - SQL text with placeholders (?)
     * @param array       $params  - query input parameters
     * @param bool        $RW      - RW transaction (true) or RO transaction(false)
     * @param bool		  $USELOCK - should we call lock() method or not 	
     * @return array	  $result	- result set, may be cached if RO transaction
     */
    public function GetOneRow($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        if (!$RW) // we can not cache RW queries
        {
            // check cache hit - if the resultset is in the cache - just return it
            $result = $this->GetCachedResultSet($sqlText, $params);

            if ($result != null)
            {
                return $result;
            }
        }

        if (!$this->isConnected)
        {
            $this->RealConnect();
            $this->isConnected = true;
        }

        $result = parent::GetOneRow($sqlText, $params, $RW, $USELOCK);

        if (!$RW) // we can not cache RW queries
        {
            // cache the result
            $this->SetCachedResultSet($result, $sqlText, $params);
        }
        return $result;
    }

    // return one value from resultset as scalar variable
    /**
     * GetValue - Get one value as scalar.
     *
     * Will fetch blobs, so avoid to do select * on large data tables!
     *
     * SQL cursor will be closed after fetch
     *
     * When using RW transaction, transaction will be started/commited immediatelly
     * after query ONLY if it was not started before
     *
     * @param string      $sqlText - SQL text with placeholders (?)
     * @param array       $params  - query input parameters
     * @param bool        $RW      - RW transaction (true) or RO transaction(false)
     * @param bool		  $USELOCK - should we call lock() method or not 	
     * @return array	  $result	- result set, may be cached if RO transaction
     */
    public function GetValue($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        $r = $this->GetOneRow($sqlText, $params, $RW, $USELOCK);
        if (is_array($r))
        {
            foreach ($r as $key => $value)
            {
                return $value;
            }
        }

        return $r;
    }

    // Use it for Execute procedure
    /**
     * Full replica of GetOneRow
     *
     * Will fetch blobs, so avoid to do select * on large data tables!
     *
     * SQL cursor will be closed after fetch
     *
     * When using RW transaction, transaction will be started/commited immediatelly
     * after query ONLY if it was not started before
     *
     * @param string      $sqlText - SQL text with placeholders (?)
     * @param array       $params  - query input parameters
     * @param bool        $RW      - RW transaction (true) or RO transaction(false)
     * @param bool		  $USELOCK - should we call lock() method or not 	
     * @return array	  $result	- result set, may be cached if RO transaction
     */
    public function Execute($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        return $this->GetOneRow($sqlText, $params, $RW, $USELOCK);
    }

    /*
      This function do the following in the original version:
      it just insert and delete a row with ID= getpid() from ts_lock table
      thrown exception must be intercepted by upper layer of code
      Note: we assume, RW transaction is already started
     */

    public function CheckLock()
    {
        return;
        /*
          $dummyQR = new FBQuery();
          $myPID = getmypid();
          $dummyQR->AssignTR(&$this->wTr);
          $dummyQR->setSQL("insert into ts_lock(id) values(?)");
          $dummyQR->Execute(array($myPID));
          $dummyQR->Drop();
          $dummyQR->setSQL("delete from ts_lock where id =?");
          $dummyQR->Execute(array($myPID));
          $dummyQR->Drop();
         */
    }

}

?>