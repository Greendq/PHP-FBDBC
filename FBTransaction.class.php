<?php
// Firebird Transaction Class
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
    Class wrapper for transactions management for Firebird SQL server
    Last revision at 2 Mar 2012 by Sergey Mereutsa <serj[at]dqteam[dot]com>.
    
    To Do: change all internal variables to comply internal naming conversion
*/

// some usefull definitions for web environment

// Read-only transaction with nowait option
define('TR_RO_NW',(IBASE_READ+IBASE_REC_NO_VERSION+IBASE_NOWAIT));

// Read-write transaction with nowait option
define('TR_RW_NW',(IBASE_WRITE+IBASE_NOWAIT));

class FBTransaction
{
	private $trIsStarted = false; // is it started?
	private $trIsRO      = false; // is this transaction Read-Only?
	private $trParams    = TR_RO_NW; // default value for TPB - read only, no wait
	private $trHandle    = NULL; // Transaction handle
	private $dbHandle    = NULL; // Databases(s) handle

	// Class constructor. By default it uses last opened database and Read Only No Wait transaction
	public function __construct($TransactionParams=TR_RO_NW, $dbHandle=NULL)
	{
		if($this->trParams!=$TransactionParams)
		{
		 $this->trParams=$TransactionParams;
		}
		$this->dbHandle=$dbHandle;
	}

	// Class destructor - rollback transaction if it is not commited before
	public function __destruct()
	{
		if($this->trIsStarted) // rollback uncommited transaction
		{
		if(FALSE==ibase_rollback($this->trHandle))
		 {
		 	throw new Exception(ibase_errmsg(), ibase_errcode());
		 }
		 else
		 {
		  $this->trIsStarted=false;
		 }
		}

	}

	// Start transaction - nothing from rocket sciense
	public function Start($TransactionParams=TR_RO_NW, $dbHandle=NULL)
	{
		if( (NULL==$this->dbHandle) && (NULL!=$dbHandle) )
		{
		 $this->dbHandle=$dbHandle;
		}
		
		$this->trParams=$TransactionParams;
		
		$this->trHandle=ibase_trans($this->trParams, $this->dbHandle);
		if(FALSE==$this->trHandle)
		{
			throw new Exception(ibase_errmsg(), ibase_errcode());
		}
		else
		{
			$this->trIsStarted=true;
		}
	}

	// commit transaction (with optional retain option) 
	// do not use commit retain unless you know what is it!
	public function Commit($CommitRetaining=false)
	{
        if(!$this->trIsStarted) return; // nothing to do if transaction was not started
        
		if($CommitRetaining) // transaction handle remain valid and it is threated as started
		{
			if(FALSE==ibase_commit_ret($this->trHandle)) // failed to commit transaction - WTF???
			{
				throw new Exception(ibase_errmsg(), ibase_errcode());
			}
		}
		else
		{
			if(FALSE==ibase_commit($this->trHandle)) // failed to commit transaction - WTF???
			{
				throw new Exception(ibase_errmsg(), ibase_errcode());
			}
			else // commited OK
			{
				$this->trIsStarted=false;
				$this->trHandle=NULL;
			}
		}
	}

	// Rollback transaction (with optional retain option)
	public function Rollback($RollbackRetaining=false)
	{
		if(!$this->trIsStarted) return; // nothing to do if transaction was not started
		
		if($RollbackRetaining) // never used on production, but let it be
		{
			if(FALSE==ibase_rollback_ret($this->trHandle)) // failed to rollback transaction - WTF???
			{
				throw new Exception(ibase_errmsg(), ibase_errcode());
			}
		}
		else
		{
			if(FALSE==ibase_rollback($this->trHandle)) // failed to rollback transaction - WTF???
			{
				throw new Exception(ibase_errmsg(), ibase_errcode());
			}
			else // rolled back OK
			{
				$this->trIsStarted=false;
				$this->trHandle=NULL;
			}
		}
	}
	
	// Return database handle 
    public function getDbHandle()
    {
     return $this->dbHandle;
    }
    
    // Return Transaction handle
    public function getHandle()
    {
     return $this->trHandle;	
    }

    // Return true if transaction is started, false otherwice
    public function isStarted()
    {
     return $this->trIsStarted;
    } 
}

?>