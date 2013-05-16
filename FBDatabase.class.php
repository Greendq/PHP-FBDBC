<?php

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
  Class wrapper to handle database connection for Firebird SQL server
  Last revision at 2 Mar 2012 by Sergey Mereutsa <serj[at]dqteam[dot]com>.

  ToDo: change all internal variables to comply internal naming conversion

 */
class FBDatabase
{

    // private section
    // Database related
    private $dbDialect = 3; // default, 1&2 not used
    private $dbBuffers = 10000; // Default number of buffers
    private $dbIsConnected = false; // no connection by default
    private $dbCharSet = "utf8"; // we are using UTF-8
    private $dbHandle = NULL;

    /*
     * Class constructor - actually, does nothing since we can delay 
     * real database connection and all variables are zeroed just by definition
     * */

    public function __construct()
    {
        
    }

    // Class destructor
    public function __destruct()
    {
        if ($this->dbIsConnected) // automatic disconnect
        {
            $this->dbIsConnected = false;
            $this->Disconnect();
        }
    }

    // connect to database - full replica of C++ wrapper class
    // void Connect (const char *User, const char *Password, const char *Role, const char *Path, const char *Charset="utf8", const ISC_LONG Pages=0) throw (fbException);
    // just connect to database
    public function Connect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset = "utf8", $dbPages = 10000)
    {
        // we can handle both connect/pconnect, but pconnect is better
        $this->dbHandle = ibase_connect($dbName, $dbUser, $dbPassword, $dbCharset, $dbPages, $this->dbDialect, $dbRole);
        if (false == $this->dbHandle)
        {
            throw new Exception(ibase_errmsg(), ibase_errcode());
        } else
        {
            $this->dbIsConnected = true;
        }
    }

    // Connect to database using Connection Pool
    public function PConnect($dbUser, $dbPassword, $dbRole, $dbName, $dbCharset = "utf8", $dbPages = 10000)
    {
        // we can handle both connect/pconnect, but pconnect is better
        $this->dbHandle = ibase_pconnect($dbName, $dbUser, $dbPassword, $dbCharset, $dbPages, $this->dbDialect, $dbRole);
        if (false == $this->dbHandle)
        {
            throw new Exception(ibase_errmsg(), ibase_errcode());
        } else
        {
            $this->dbIsConnected = true;
        }
    }

    // disconnects (detach) from database
    public function Disconnect()
    {
        if ($this->dbIsConnected)
        {
            if (false == ibase_close($this->dbHandle))
            {
                throw new Exception(ibase_errmsg(), ibase_errcode());
            } else
            {
                $this->dbIsConnected = false;
            }
        }
//		else // should never happen, but who knows? ;-)
//		{
//		 error_log("FBDatabase: Call to Disconnect() when not connected.",0);
//		}
    }

    public function isConnected()
    {
        return $dbIsConnected;
    }

    public function getDbHandle()
    {
        return $this->dbHandle;
    }

}

?>