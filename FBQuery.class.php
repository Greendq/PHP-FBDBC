<?php
// Firebird Query Class
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
  Wrapper object for management queries to Firebird SQl server
  Last revision at 2 Mar 2012 by Sergey Mereutsa <serj[at]dqteam[dot]com>.

  To Do: change all internal variables to comply internal naming conversion

 */
require_once(__DIR__ . '/FBTransaction.class.php');

// Some helper classes
// class helper due the lack of enums - query parameters type (IN, OUT, BOth, NONE)
class QRPType
{

    const qrNULL = 0; // no parameters for query
    const qrIN = 1; // query has only IN parameters
    const qrOUT = 2; // query has only OUT parameters
    const qrIN_OUT = 3; // query has both IN and OUT parameters

}

class QueryParameter
{

    protected $prData = NULL; // parameter data pointer
    protected $prType = NULL; // field type (SQL type)
    protected $prName = NULL; // parameter (field) name, not always available
    protected $prRelation = NULL; // relation object, usually table, but can be selectable procedure
    protected $prNull = false; // is it null now

    public function isNull()
    {
        return $prNull;
    }

    // deprecated function
    public function isBlob()
    {
        if ($this->prType == 'BLOB')
        {
            return true;
        }
        else
            return false;
    }

    // deprecated, compatibility only  - remove in future versions
    public function asText()
    {
        
    }

}

// class implementation itself
class FBQuery
{

    protected $dbHandle = NULL; // Database(s) handle for query
    protected $trHandle = NULL; // Internal transaction handle for query (for lazzy programmers)
    protected $qrStatementHandle = NULL; // (query) statement handle, filled by ibase_prepare
    protected $qrHandle = NULL; // internal query handle, filled by last call of ibase_execute
    private $qrIsPrepared = false; // is query already prepared?
    private $qrIsBinded = false; // is query binded?
    private $qrIsDescribed = false; // is query described?
    private $qrPType = NULL;  // query parameters type - can be only one from defined in QRPType
    private $qrSQLText = '';    // SQL query text itself
    private $qrInParamCount = 0;     // input parameters count
    private $qrOutParamCount = 0;     // output params count
    private $qrParams = array(); //!< store object parameters for input
    private $qrFields = array(); //!< store object parameters for output

    // Class constructor

    public function __construct($sqlText = NULL)
    {
        $this->qrSQLText = $sqlText;
    }

    // Class destructor
    public function __destruct()
    {
        self::Unprepare();
    }

    public function setSQL($sqlText)
    {
        // unprepare if prepared and cleanup all handles
        self::Unprepare();
        $this->qrSQLText = $sqlText;
    }

    // assign database and transaction for query, transaction can be omited - we will use internal instead
    // not used any more, deprecated
    public function Assign($FBDb, $FBTr = NULL)
    {
        
    }

    // Assign Transaction for query. If transaction is not assigned - we will use our internal RO transaction
    public function AssignTR(FBTransaction $FBTr)
    {
        $this->dbHandle = $FBTr->getDbHandle();
        $this->trHandle = $FBTr->getHandle();
    }

    // Assign Database for query - 
    // not used any more, deprecated
    public function AssignDB($FBDb)
    {
        
    }

    //! Prepare DSQL statement - statement is allocated automatically
    //! Preapare() call can be omited, statement will be prepared automatically
    public function Prepare()
    {
        if (!$this->qrIsPrepared)
        {
            $this->qrStatementHandle = ibase_prepare($this->dbHandle, $this->trHandle, $this->qrSQLText);
            if (FALSE == $this->qrStatementHandle)
            {
                throw new Exception(ibase_errmsg(), ibase_errcode());
            } else
            {
                $this->qrIsPrepared = true;
            }

            // getting info about expected parameters and doing coercion if needed
            $this->qrInParamCount = @ibase_num_params($this->qrStatementHandle);

            // getting info about fields (output)
            $this->qrOutParamCount = @ibase_num_fields($this->qrStatementHandle);
        }
    }

    //! Execute SQL query
    public function Execute($sqlParams = array(), $fetchOne = false)
    {
        // ToDo: check params count
        // check if statement was prepared before
        if (!$this->qrIsPrepared)
        {
            self::Prepare();
        }

        if (is_scalar($sqlParams)) // convert scalar value to an array
        {
            $sqlParams = array($sqlParams);
        }

        $this->qrParams = $sqlParams; // saving query parameters data

        array_unshift($sqlParams, $this->qrStatementHandle);
        $this->qrHandle = @call_user_func_array('ibase_execute', $sqlParams);
        if (FALSE === $this->qrHandle)
        {
            throw new Exception(ibase_errmsg(), ibase_errcode());
        }

        if (TRUE === $this->qrHandle) // query succesful, but not returned any row
        {
            $res = array();
            for ($i = 0; $i < $this->qrOutParamCount; $i++)
            {
                $this->qrFields[$i] = NULL;
            }
            return $this->qrFields;
        }
        // Check if we need to fetch one record from cursor
        if ($fetchOne)
        {
            $res = @ibase_fetch_row($this->qrHandle);
            return $res;
        }
    }

    //! Fetch row from opened cursor as number-indexed array a[0]...a[n]
    //! fetchFlag is a combination of the constants IBASE_TEXT and IBASE_UNIXTIME ORed together.
    //! Passing IBASE_TEXT will cause this function to return BLOB contents instead of BLOB ids.
    //! Passing IBASE_UNIXTIME will cause this function to return date/time values as Unix timestamps
    //! instead of as formatted strings.
    public function Fetch($fetchFlag = 0)
    {
        @$res = @ibase_fetch_row($this->qrHandle, $fetchFlag); // inhibit PHP warnings
        if ((FALSE === $res) && (ibase_errcode() != FALSE)) // failed to execute or fetch
        {
            throw new Exception(ibase_errmsg(), ibase_errcode());
        }
        return $res;
    }

    //! Fetch row from opened cursor as an associative (hash) array a['F1']...a['Fn']
    public function FetchHashed($fetchFlag = 0)
    {
        @$res = ibase_fetch_assoc($this->qrHandle, $fetchFlag);
        if ((FALSE === $res) && (ibase_errcode() != FALSE)) // failed to execute or fetch
        {
            throw new Exception(ibase_errmsg(), ibase_errcode());
        }
        return $res;
    }

    // Close opened cursor, but do not unprepare statement
    public function Close()
    {
        if ($this->qrHandle) // if resultset is not empty
        {
            if (FALSE === @ibase_free_result($this->qrHandle))
            {
                throw new Exception(ibase_errmsg(), ibase_errcode());
            }
            $this->qrHandle = NULL;
        }
    }

    // Drop opened cursor and unprepare statement
    public function Drop()
    {
        self::Unprepare();
    }

    //! Free resultset and unprepare statement
    public function Unprepare()
    {
        if ($this->qrIsPrepared)
        {
            if (TRUE === $this->qrHandle) // if resultset is not empty
            {
                if (FALSE === @ibase_free_result($this->qrHandle))
                {
                    throw new Exception(ibase_errmsg(), ibase_errcode());
                }
            }

            if (FALSE === @ibase_free_query($this->qrStatementHandle)) // if call was not successful...
            {
                throw new Exception(ibase_errmsg(), ibase_errcode());
            }
            $this->qrIsPrepared = false;
            $this->qrIsBinded = false;
            $this->qrIsDescribed = false;
            $this->qrHandle = NULL;
        }
    }

}

?>