<?php
/*
 Class wrapper for BLOBs management for Firebird SQL server

Note: usage of this class is a bit deprecated
*/
require_once("FBDatabase.class.php");
require_once("FBTransaction.class.php");

class FBBlob
{
	private $blHandle=NULL; // internal blob handle
	private $dbHandle=NULL; // database connection handler
	private $trHandle=NULL; // Transaction handle - not necessary

	private $blIsOpen=false; // indicate is blob open or not

	private $blData  =NULL; // BLOB data itself
	private $blID    =NULL; // BLOB id (string, not handle!)

	// DB is optional, we can provide it later
	public function __construct(FBDatabase &$db=NULL)
	{
		if(!is_null($db))
		{
		 $this->dbHandle=$db->getDbHandle();
		}
	}

	public function __destruct()
	{
		if($this->blIsOpen && !is_null($this->blHandle)) // close open blob
		{
		 ibase_blob_close($this->blHandle);
		}
	}

	// read BLOB`s data from database by given blob_id, blob content returned
	// if DB provided - we wil use it
	public function &getBlobData($blob_id, FBDatabase &$db=null)
	{
		// if $db is not null - use it as new handle
		if(!is_null($db))
		{
			// close opened blob if any
			if($this->blIsOpen) // close open blob
			{
				ibase_blob_close($this->blHandle);
			}
			$this->dbHandle=$db->getDbHandle();
		}
		// getting BLOB len
		$blob_len=$this->getBlobLen($blob_id);
		if($blob_len>0) // do not open blob for reading 0 bytes
		{
			$this->blHandle=ibase_blob_open($this->dbHandle,$blob_id);
			if(FALSE===$this->blHandle)
			{
				throw new Exception(ibase_errmsg(), ibase_errcode());
			}
			$this->blData=ibase_blob_get($this->blHandle, $blob_len);
		}
		return $this->blData;
	}

	// write data to the blob and return blob ID for newly created blob
	public function setBlobData($blData)
	{
		// creating new blob
		$this->blHandle=ibase_blob_create($this->dbHandle);
		if(FALSE===$this->blHandle)
		{
			throw new Exception(ibase_errmsg(), ibase_errcode());
		}

		// writting blob data
		ibase_blob_add($this->blHandle,$blData);
		// closing blob
		$this->blID=ibase_blob_close($this->blHandle);
		if(FALSE===$this->blID)
		{
			throw new Exception(ibase_errmsg(), ibase_errcode());
		}

	 // return new blob id
	 return $this->blID;
	}

	public function getBlobInfo($blob_id)
	{
		//$this->blHandle=ibase_blob_open($blob_id);
		$blob_info = ibase_blob_info($blob_id);
		return $blob_info;
	}

	// This function returns blob length in bytes (use with care for multibyte text blobs)
	public function getBlobLen($blob_id)
	{
		$blob_info = ibase_blob_info($blob_id);
		return $blob_info[0];
	}
}

?>