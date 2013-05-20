<?php

// Firebird Connection Class
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

 */

require_once(__DIR__ . "/FBDatabase.class.php");
require_once(__DIR__ . "/FBTransaction.class.php");
require_once(__DIR__ . "/FBQuery.class.php");

class FBConnection
{

    /**
     * @var FBDatabase	 */
    public $db = NULL; // database

    /**
     * @var FBTransaction */
    public $rTr = NULL; // read-only transaction

    /**
     * @var FBTransaction */
    public $wTr = NULL; // read-write transaction

    /**
     * @var FBQuery	 */
    public $rQr = NULL; // read-only Query

    /**
     * @var FBQuery  */
    public $wQr = NULL; // read-write Query

    public function __construct()
    {
        $this->db = new FBDatabase();

        $this->rTr = new FBTransaction();
        $this->wTr = new FBTransaction();

        $this->rQr = new FBQuery(); // RO transaction will be autostarted on connect
        $this->wQr = new FBQuery();
    }

    public function __destruct()
    {
        // close all active connections and rollback all active transactions
        $this->rTr->Rollback();
        $this->wTr->Rollback();
        $this->db->disconnect();
    }

    // This method allow us to connect to database using connection pool
    public function Connect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset = "utf8", $dbPages = 10000, $usePool = false)
    {
        // connect to database
        error_log("Use pool: ".(bool)$usePool, 0);
        if (!(bool)$usePool)
        {
            $this->db->Connect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset, $dbPages);
        } else
        {
            $this->db->PConnect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset, $dbPages);
        }
    }

    // compatibility only, not necessary to call it - DB will be detached in destructor
    public function Disconnect()
    {
        $this->rTr->Rollback();
        $this->wTr->Rollback();
        $this->db->disconnect();
    }

    /**
     * 	Start internal default RW transaction
     * 	We assume connection is already active
     */
    public function Start($trOptions = TR_RW_NW)
    {
        // to avoid double start and uncommited long-running transactions
        // we roll back active transaction if any
        $this->Rollback();

        // now we can start RW transaction
        $this->wTr->Start($trOptions, $this->db->getDbHandle());
    }

    // commit default RW transaction
    public function Commit($doRetain = false)
    {
        $this->wTr->Commit($doRetain);
    }

    // rollback default RW transaction
    public function Rollback($doRetain = false)
    {
        $this->wTr->Rollback($doRetain);
    }

    /**
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
     * @return array
     */
    public function GetAllRows($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        // check if it is RW transaction
        // check if transaction started
        $doCommit = false;
        if (true == $RW)
        {
            if ($this->wTr->isStarted())
            {
                $activeTransaction = & $this->wTr;
            } else // lazzy programmer, ok, will start RW transaction for him/her now
            {
                $this->wTr->Start(TR_RW_NW, $this->db->getDbHandle());
                $doCommit = true; // and commit it after execution
                $activeTransaction = & $this->wTr;
            }
            // check if we need use lock table
            if ($USELOCK)
            {
                self::CheckLock();
            }
        } else // RO transaction already started
        {
            $this->rTr->Commit();
            $this->rTr->Start(TR_RO_NW, $this->db->getDbHandle());
            $activeTransaction = & $this->rTr;
            $doCommit = true; //it costs nothing, so - commit it
        }

        $result = array();

        // using internal temporary query object to fetch data
        if (true == $RW)
        {
            $activeQuery = $this->wQr;
        } else
        {
            $activeQuery = $this->rQr;
        }

        $activeQuery->AssignTR($activeTransaction);
        $activeQuery->setSQL($sqlText);
        $activeQuery->Execute($params);
        $r = $activeQuery->FetchHashed(IBASE_TEXT);
        $i = 0;
        if (is_array($r))
        {
            while (FALSE !== $r)
            {
                $rl = array();
                // making lowercase aliases for code which rely on ADODB resultsets
                foreach ($r as $key => & $value)
                {
                    if (mb_strtolower($key) != $key)
                    {
                        $rl[mb_strtolower($key)] = & $r[$key];
                    }
                }
                $result[$i++] = $rl;
                $r = $activeQuery->FetchHashed(IBASE_TEXT);
            }
        }
        $activeQuery->Drop();
        // commit transaction after succesfull execution
        if (true == $doCommit)
        {
            $activeTransaction->Commit();
        }
       return $result;
    }

    // return one row from resultset as 1D hash array
    /**
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
     * @return array
     */
    public function GetOneRow($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        // check if it is RW transaction
        // check if transaction started
        $doCommit = false;
        if (true == $RW)
        {
            if ($this->wTr->isStarted())
            {
                $activeTransaction = & $this->wTr;
            } else // lazzy programmer, ok, will start RW transaction for him/her now
            {
                $this->wTr->Start(TR_RW_NW, $this->db->getDbHandle());
                $doCommit = true; // and commit it after execution
                $activeTransaction = & $this->wTr;
            }
            // check if we need use lock table
            if ($USELOCK)
            {
                self::CheckLock();
            }
        } else // RO transaction already started
        {
            $this->rTr->Commit();
            $this->rTr->Start(TR_RO_NW, $this->db->getDbHandle());
            $activeTransaction = & $this->rTr;
            $doCommit = true; // it costs nothing for us
        }

        // using internal temporary query object to fetch data
        if (true == $RW)
        {
            $activeQuery = $this->wQr;
        } else
        {
            $activeQuery = $this->rQr;
        }

        $activeQuery->AssignTR($activeTransaction);
        $activeQuery->setSQL($sqlText);
        $activeQuery->Execute($params);
        $r = $activeQuery->FetchHashed(IBASE_TEXT);
        if (is_array($r))
        {
            $rl = array();
            // making lowercase aliases for code which rely on ADODB resultsets
            foreach ($r as $key => & $value)
            {
                if (mb_strtolower($key) != $key)
                {
                    $rl[mb_strtolower($key)] = & $r[$key];
                }
            }
            unset($r);
            $r = & $rl;
        }

        $activeQuery->Drop();
        // commit transaction after succesfull execution
        if (true == $doCommit)
        {
            $activeTransaction->Commit();
        }
        return $r;
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
     * @return array
     */
    public function GetValue($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        $r = $this->GetOneRow($sqlText, $params, $RW, $USELOCK);
        if (is_array($r))
        {
            foreach ($r as $key => & $value)
            {
                return $value;
            }
        }
        else
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
     * @return array
     */
    public function Execute($sqlText, $params = array(), $RW = false, $USELOCK = true)
    {
        return $this->GetOneRow($sqlText, $params, $RW, $USELOCK);
    }

    /*
      This function do the following:
      it just insert and delete a row with ID= getpid() from ts_lock table
      thrown exception must be intercepted by upper layer of code
      Note: we assume, RW transaction is already started
     */

    public function CheckLock()
    {
        return;
        /*  Uncomment code below and comment previous line to use locks
         *$dummyQR = new FBQuery();
          $myPID = getmypid();
          $dummyQR->AssignTR($this->wTr);
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