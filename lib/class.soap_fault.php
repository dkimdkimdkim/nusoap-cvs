<?php




/**
* soap_fault class, allows for creation of faults
* mainly used for returning faults from deployed functions
* in a server instance.
* @author   Dietrich Ayala <dietrich@ganx4.com>
* @version  $Id$
* @access public
*/
class soap_fault extends nusoap_base {

	var $faultcode;
	var $faultactor;
	var $faultstring;
	var $faultdetail;

	/**
	* constructor
    *
    * @param string $faultcode (client | server)
    * @param string $faultactor only used when msg routed between multiple actors
    * @param string $faultstring human readable error message
    * @param mixed $faultdetail detail, typically a string or array of string
	*/
	function soap_fault($faultcode,$faultactor='',$faultstring='',$faultdetail=''){
		parent::nusoap_base();
		$this->faultcode = $faultcode;
		$this->faultactor = $faultactor;
		$this->faultstring = $faultstring;
		$this->faultdetail = $faultdetail;
	}

	/**
	* serialize a fault
	*
	* @return	string	The serialization of the fault instance.
	* @access   public
	*/
	function serialize(){
		$ns_string = '';
		foreach($this->namespaces as $k => $v){
			$ns_string .= "\n  xmlns:$k=\"$v\"";
		}
		$return_msg =
			'<?xml version="1.0" encoding="'.$this->soap_defencoding.'"?>'.
			'<SOAP-ENV:Envelope SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"'.$ns_string.">\n".
				'<SOAP-ENV:Body>'.
				'<SOAP-ENV:Fault>'.
					$this->serialize_val($this->faultcode, 'faultcode').
					$this->serialize_val($this->faultactor, 'faultactor').
					$this->serialize_val($this->faultstring, 'faultstring').
					$this->serialize_val($this->faultdetail, 'detail').
				'</SOAP-ENV:Fault>'.
				'</SOAP-ENV:Body>'.
			'</SOAP-ENV:Envelope>';
		return $return_msg;
	}
}




?>