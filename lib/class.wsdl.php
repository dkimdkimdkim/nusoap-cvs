<?php

/**
* parses a WSDL file, allows access to it's data, other utility methods
*
* @author   Dietrich Ayala <dietrich@ganx4.com>
* @access   public
*/
class wsdl extends XMLSchema {
	var $wsdl;
	// define internal arrays of bindings, ports, operations, messages, etc.
    var $message = array();
	var $complexTypes = array();
	var $messages = array();
	var $currentMessage;
	var $currentOperation;
	var $portTypes = array();
	var $currentPortType;
	var $bindings = array();
	var $currentBinding;
	var $ports = array();
	var $currentPort;
	var $opData = array();
	var $status = '';
	var $documentation = false;
    var $endpoint = '';
	// array of wsdl docs to import
	var $import = array();
	// parser vars
	var $parser;
	var $position = 0;
	var $depth = 0;
	var $depth_array = array();

	/**
	* constructor
	*
	* @param    string $wsdl WSDL document URL
	* @access   public
	*/
	function wsdl($wsdl=''){
		$this->wsdl = $wsdl;

		// parse wsdl file
		if($wsdl != ""){
			$this->debug('initial wsdl file: '.$wsdl);
			$this->parseWSDL($wsdl);
		}

		// imports
		if(sizeof($this->import) > 0){
			foreach($this->import as $ns => $url){
				$this->debug('importing wsdl from '.$url);
				$this->parseWSDL($url);
			}
		}

	}

	/**
	* parses the wsdl document
	*
	* @param    string $wsdl path or URL
	* @access   private
	*/
	function parseWSDL($wsdl=''){
		if($wsdl == ''){
        	$this->debug('no wsdl passed to parseWSDL()!!');
			$this->setError('no wsdl passed to parseWSDL()!!');
			return false;
        }

        $this->debug('getting '.$wsdl);
	    /* old
		if ($fp = @fopen($wsdl,'r')) {
        	$wsdl_string = '';
			while($data = fread($fp, 32768)) {
				$wsdl_string .= $data;
			}
			fclose($fp);
		} else {
			$this->setError('bad path to WSDL file.');
			return false;
		}*/
		
		// *** start new code added ***
		// props go to robert tuttle for the wsdl-grabbing code
        // parse $wsdl for url format
        $wsdl_props = parse_url($wsdl);
		
        if ( isset($wsdl_props['host']) ) {
        // $wsdl seems to be a valid url, not a file path, do an fsockopen/HTTP GET
        
            $fsockopen_timeout = 30;
        	
            // check if a port value is supplied in url
            if ( isset($wsdl_props['port']) ) {
                // yes
                $wsdl_url_port = $wsdl_props['port'];
            } else {
                // no, assign port number, based on url protocol (scheme)
                switch ($wsdl_props['scheme']) {
                    case ('https') :
                    case ('ssl') :
                    case ('tls') :
                        $wsdl_url_port = 443;
                        break;
                    case ('http') :
                    default :
                        $wsdl_url_port = 80;
                }
            }
        
            if ($fp = fsockopen($wsdl_props['host'], $wsdl_url_port, $fsockopen_errnum, $fsockopen_errstr, $fsockopen_timeout)) {
            
                // perform HTTP GET for WSDL file
                fputs($fp, "GET " . $wsdl_props['path'] . " HTTP/1.0\r\nHost: ".$wsdl_props['host']."\r\n\r\n");
            
                while (fgets($fp, 1024) != "\r\n") {
                    // do nothing, just read/skip past HTTP headers
                    // HTTP headers end with extra CRLF before content body
                }
            
                // read in WSDL just like regular fopen()
                $wsdl_string = '';
                while($data = fread($fp, 32768)) {
                    $wsdl_string .= $data;
                }
                fclose($fp);
				
				//print '<xmp>'.$wsdl_string.'</xmp>';
				
             } else {
                $this->setError('bad path to WSDL file.');
                return false;
             }
            
            
        } else {  
        // $wsdl seems to be a non-url file path, do the regular fopen
     
            if ($fp = @fopen($wsdl,'r')) {
                    
            	$wsdl_string = '';
                while($data = fread($fp, 32768)) {
                    $wsdl_string .= $data;
                }
                fclose($fp);
                
             } else {
                $this->setError('bad path to WSDL file.');
                return false;
             }
        
        }

		// *** end new code added ***

	    // Create an XML parser.
	    $this->parser = xml_parser_create();
	    // Set the options for parsing the XML data.
	    //xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
	    xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, 0);
	    // Set the object for the parser.
	    xml_set_object($this->parser, $this);
	    // Set the element handlers for the parser.
	    xml_set_element_handler($this->parser, 'start_element','end_element');
	    xml_set_character_data_handler($this->parser,'character_data');

	    // Parse the XML file.
	    if(!xml_parse($this->parser,$wsdl_string,true)){
			// Display an error message.
			$errstr = sprintf(
            	'XML error on line %d: %s',
				xml_get_current_line_number($this->parser),
				xml_error_string(xml_get_error_code($this->parser))
				);
			$this->debug('XML parse error: '.$errstr);
			$this->setError('Parser error: '.$errstr);
			return false;
	    }

		xml_parser_free($this->parser);

		// add new data to operation data
		foreach($this->bindings as $binding => $bindingData){
			if(isset($bindingData['operations']) && is_array($bindingData['operations'])){
				foreach($bindingData['operations'] as $operation => $data){
					$this->debug('post-parse data gathering for '.$operation);
		    		$this->bindings[$binding]['operations'][$operation]['input'] = array_merge($this->bindings[$binding]['operations'][$operation]['input'],$this->portTypes[ $bindingData['portType'] ][$operation]['input']);
					$this->bindings[$binding]['operations'][$operation]['output'] = array_merge($this->bindings[$binding]['operations'][$operation]['output'],$this->portTypes[ $bindingData['portType'] ][$operation]['output']);
					$this->bindings[$binding]['operations'][$operation]['input']['parts'] = $this->messages[ $this->bindings[$binding]['operations'][$operation]['input']['message'] ];
					$this->bindings[$binding]['operations'][$operation]['output']['parts'] = $this->messages[ $this->bindings[$binding]['operations'][$operation]['output']['message'] ];
					if(!isset($this->bindings[$binding]['operations'][$operation]['style'])){
						$this->bindings[$binding]['operations'][$operation]['style'] = $bindingData['style'];
					}
					$this->bindings[$binding]['operations'][$operation]['transport'] = $bindingData['transport'];
					$this->bindings[$binding]['operations'][$operation]['documentation'] = isset($this->portTypes[ $bindingData['portType'] ][$operation]['documentation']) ? $this->portTypes[ $bindingData['portType'] ][$operation]['documentation'] : '';
					$this->bindings[$binding]['operations'][$operation]['endpoint'] = $bindingData['endpoint'];
				}
			}
		}
		return true;
	}

	/**
	* start-element handler
	*
	* @param    string $parser XML parser object
	* @param    string $name element name
	* @param    string $attrs associative array of attributes
	* @access   private
	*/
	function start_element($parser, $name, $attrs) {

		if($this->status == 'schema' || ereg('schema$',$name)){
			//$this->debug("startElement for $name ($attrs[name]). status = $this->status (".$this->getLocalPart($name).")");
			$this->status = 'schema';
			$this->schemaStartElement($parser,$name,$attrs);
		} else {
			// position in the total number of elements, starting from 0
			$pos = $this->position++;
			$depth = $this->depth++;
			// set self as current value for this depth
			$this->depth_array[$depth] = $pos;
			$this->message[$pos] = array('cdata'=>'');

			// get element prefix
			if(ereg(':',$name)){
				// get ns prefix
				$prefix = substr($name,0,strpos($name,':'));
                // get ns
                $namespace = isset($this->namespaces[$prefix]) ? $this->namespaces[$prefix] : $this->namespaces['tns'];
				// get unqualified name
				$name = substr(strstr($name,':'),1);
			}

            if(count($attrs) > 0){
        		foreach($attrs as $k => $v){
                    // if ns declarations, add to class level array of valid namespaces
					if(ereg("^xmlns",$k)){
						if($ns_prefix = substr(strrchr($k,':'),1)){
							$this->namespaces[$ns_prefix] = $v;
						} else {
							$this->namespaces['ns'.(count($this->namespaces)+1)] = $v;
						}
						if($v == 'http://www.w3.org/2001/XMLSchema'|| $v == 'http://www.w3.org/1999/XMLSchema'){
							$this->XMLSchemaVersion = $v;
							$this->namespaces['xsi'] = $v.'-instance';
						}
					}//
                    // expand each attribute
                	$k = strpos($k,':') ? $this->expandQname($k) : $k;
                	if($k != 'location' && $k != 'soapAction' && $k != 'namespace'){
                    	$v = strpos($v,':') ? $this->expandQname($v) : $v;
                    }
        			$eAttrs[$k] = $v;
        		}
        		$attrs = $eAttrs;
        	} else {
        		$attrs = array();
        	}

			// find status, register data
			switch($this->status){
				case 'message':
					if($name == 'part'){
						if($attrs['type']){
							$this->debug( "msg ".$this->currentMessage.": found part $attrs[name]: ".implode(',',$attrs));
							$this->messages[$this->currentMessage][$attrs['name']] = $attrs['type'];
						}
						if(isset($attrs['element'])){
							$this->messages[$this->currentMessage][$attrs['name']] = $attrs['element'];
						}
					}
				break;
				case 'portType':
					switch($name){
						case 'operation':
							$this->currentPortOperation = $attrs['name'];
							$this->debug("portType $this->currentPortType operation: $this->currentPortOperation");
							if(isset($attrs['parameterOrder'])){
                            	$this->portTypes[$this->currentPortType][$attrs['name']]['parameterOrder'] = $attrs['parameterOrder'];
                        	}
						break;
						case 'documentation':
							$this->documentation = true;
						break;
						// merge input/output data
						default:
							$m = isset($attrs['message']) ? $this->getLocalPart($attrs['message']) : '';
                            $this->portTypes[$this->currentPortType][$this->currentPortOperation][$name]['message'] = $m;
						break;
					}
				break;
				case 'binding':
					switch($name){
						case 'binding':
							// get ns prefix
							if(isset($attrs['style'])){
								$this->bindings[$this->currentBinding]['prefix'] = $prefix;
							}
							$this->bindings[$this->currentBinding] = array_merge($this->bindings[$this->currentBinding],$attrs);
						break;
						case 'header':
							$this->bindings[$this->currentBinding]['operations'][$this->currentOperation][$this->opStatus]['headers'][] = $attrs;
						break;
						case 'operation':
							if(isset($attrs['soapAction'])){
								$this->bindings[$this->currentBinding]['operations'][$this->currentOperation]['soapAction'] = $attrs['soapAction'];
							}
                            if(isset($attrs['style'])){
                            	$this->bindings[$this->currentBinding]['operations'][$this->currentOperation]['style'] = $attrs['style'];
                            }
							if(isset($attrs['name'])) {
								$this->currentOperation = $attrs['name'];
								$this->debug("current binding operation: $this->currentOperation");
								$this->bindings[$this->currentBinding]['operations'][$this->currentOperation]['name'] = $attrs['name'];
								$this->bindings[$this->currentBinding]['operations'][$this->currentOperation]['binding'] = $this->currentBinding;
								$this->bindings[$this->currentBinding]['operations'][$this->currentOperation]['endpoint'] = isset($this->bindings[$this->currentBinding]['endpoint']) ? $this->bindings[$this->currentBinding]['endpoint'] : '';
							}
						break;
						case 'input':
							$this->opStatus = 'input';
						break;
						case 'output':
							$this->opStatus = 'output';
						break;
						case 'body':
							if(isset($this->bindings[$this->currentBinding]['operations'][$this->currentOperation][$this->opStatus])){
                            	$this->bindings[$this->currentBinding]['operations'][$this->currentOperation][$this->opStatus]= array_merge($this->bindings[$this->currentBinding]['operations'][$this->currentOperation][$this->opStatus],$attrs);
                            } else {
                            	$this->bindings[$this->currentBinding]['operations'][$this->currentOperation][$this->opStatus] = $attrs;
                            }
						break;
					}
				break;
				case 'service':
					switch($name){
						case 'port':
							$this->currentPort = $attrs['name'];
							$this->debug('current port: '.$this->currentPort);
							$this->ports[$this->currentPort]['binding'] = $this->getLocalPart($attrs['binding']);

						break;
						case 'address':
							$this->ports[$this->currentPort]['location'] = $attrs['location'];
							$this->ports[$this->currentPort]['bindingType'] = $namespace;
                            $this->bindings[ $this->ports[$this->currentPort]['binding'] ]['bindingType'] = $namespace;
							$this->bindings[ $this->ports[$this->currentPort]['binding'] ]['endpoint'] = $attrs['location'];
						break;
					}
				break;
			}
			// set status
			switch($name){
				case "import":
					if(isset($attrs['location'])){
						$this->import[$attrs['namespace']] = $attrs['location'];
					}
				break;
				case 'types':
					$this->status = 'schema';
				break;
				case 'message':
					$this->status = 'message';
					$this->messages[$attrs['name']] = array();
					$this->currentMessage = $attrs['name'];
				break;
				case 'portType':
					$this->status = 'portType';
					$this->portTypes[$attrs['name']] = array();
					$this->currentPortType = $attrs['name'];
				break;
				case "binding":
					if(isset($attrs['name'])){
						// get binding name
						if(strpos($attrs['name'],':')){
							$this->currentBinding = $this->getLocalPart($attrs['name']);
						} else {
							$this->currentBinding = $attrs['name'];
						}
						$this->status = 'binding';
						$this->bindings[$this->currentBinding]['portType'] = $this->getLocalPart($attrs['type']);
						$this->debug("current binding: $this->currentBinding of portType: ".$attrs['type']);
					}
				break;
				case 'service':
					$this->serviceName = $attrs['name'];
					$this->status = 'service';
				break;
				case 'definitions':
					foreach ($attrs as $name=>$value) {
						$this->wsdl_info[$name]=$value;
					}
				break;
			}
		}
	}

	/**
	* end-element handler
	*
	* @param    string $parser XML parser object
	* @param    string $name element name
	* @access   private
	*/
	function end_element($parser, $name) {
	    // unset schema status
		if(ereg('types$',$name) || ereg('schema$',$name)){
			$this->status = "";
		}
		if($this->status == 'schema'){
			$this->schemaEndElement($parser, $name);
		} else {
			// bring depth down a notch
			$this->depth--;
		}
		// end documentation
		if($this->documentation){
			$this->portTypes[$this->currentPortType][$this->currentPortOperation]['documentation'] = $this->documentation;
			$this->documentation = false;
		}
	}

	/**
	* element content handler
	*
	* @param    string $parser XML parser object
	* @param    string $data element content
	* @access   private
	*/
	function character_data($parser, $data){
		$pos = isset($this->depth_array[$this->depth]) ? $this->depth_array[$this->depth] : 0;
		if(isset($this->message[$pos]['cdata'])){
        	$this->message[$pos]['cdata'] .= $data;
        }
		if($this->documentation){
			$this->documentation .= $data;
		}
	}


	function getBindingData($binding){
		if(is_array($this->bindings[$binding])){
			return $this->bindings[$binding];
		}
	}

	function getMessageData($operation,$portType,$msgType){
		$name = $this->opData[$operation][$msgType]['message'];
		$this->debug( "getting msgData for $name, using $operation,$portType,$msgType<br>" );
		return $this->messages[$name];
	}

    /**
    * returns an assoc array of operation names => operation data
    * NOTE: currently only supports multiple services of differing binding types
    * This method needs some work
    *
    * @param string $bindingType eg: soap, smtp, dime (only soap is currently supported)
    * @return array
    * @access public
    */
	function getOperations($bindingType = 'soap'){
		if($bindingType == 'soap'){
			$bindingType = 'http://schemas.xmlsoap.org/wsdl/soap/';
		}
		// loop thru ports
		foreach($this->ports as $port => $portData){
			// binding type of port matches parameter
			if($portData['bindingType'] == $bindingType){
				// get binding
				return $this->bindings[ $portData['binding'] ]['operations'];
			}
		}
		return array();
	}

    /**
    * returns an associative array of data necessary for calling an operation
    *
    * @param string $operation, name of operation
    * @param string $bindingType, type of binding eg: soap
	* @return array
    * @access public
    */
	function getOperationData($operation,$bindingType='soap'){
		if($bindingType == 'soap'){
			$bindingType = 'http://schemas.xmlsoap.org/wsdl/soap/';
		}
		// loop thru ports
		foreach($this->ports as $port => $portData){
			// binding type of port matches parameter
			if($portData['bindingType'] == $bindingType){
				// get binding
				foreach($this->bindings[ $portData['binding'] ]['operations'] as $bOperation => $opData){
					if($operation == $bOperation){
						return $opData;
					}
				}
			}
		}
	}

	/**
	* serialize the parsed wsdl
	*
    * @return string, serialization of WSDL
	* @access   public
	*/
	function serialize(){
		$xml = '<?xml version="1.0"?><definitions';
		foreach($this->namespaces as $k => $v){
			$xml .= " xmlns:$k=\"$v\"";
		}
		$xml .= '>';

		// imports
		if(sizeof($this->import) > 0){
			foreach($this->import as $ns => $url){
				$xml .= '<import location="'.$url.'" namespace="'.$ns.'" />';
			}
		}

		// types
		if($this->schema){
			$xml .= '<types>';
			$xml .= $this->serializeSchema();
			$xml .= '</types>';
		}

		// messages
		if(count($this->messages) >= 1){
			foreach($this->messages as $msgName => $msgParts){
				$xml .= '<message name="'.$msgName.'">';
				foreach($msgParts as $partName => $partType){
					$xml .= '<part name="'.$partName.'" type="'.$this->getPrefixFromNamespace($this->getPrefix($partType)).':'.$this->getLocalPart($partType).'" />';
				}
				$xml .= '</message>';
			}
		}
		// portTypes
		if(count($this->portTypes) >= 1){
			foreach($this->portTypes as $portTypeName => $portOperations){
				$xml .= '<portType name="'.$portTypeName.'">';
				foreach($portOperations as $portOperation => $portOpData){
					$xml .= '<operation name="'.$portOperation.'" parameterOrder="'.$portOpData['parameterOrder'].'">';
                    foreach($portOpData as $name => $attrs){
						if($name != 'parameterOrder'){
                        $xml .= '<'.$name;
						if(is_array($attrs)){
							foreach($attrs as $k => $v){
								$xml .= " $k=\"$v\"";
							}
						}
						$xml .= '/>';
                    	}
					}
					$xml .= '</operation>';
				}
				$xml .= '</portType>';
			}
		}
		// bindings
		if(count($this->bindings) >= 1){
			foreach($this->bindings as $bindingName => $attrs){
				$xml .= '<binding name="'.$msgName.'" type="'.$attrs['type'].'">';
				$xml .= "<soap:binding style=\"".$attrs['style'].'" transport="'.$attrs['transport'].'"/>';
				foreach($attrs["operations"] as $opName => $opParts){
					$xml .= '<operation name="'.$opName.'">';
					$xml .= '<soap:operation soapAction="'.$opParts['soapAction'].'"/>';
					$xml .= '<input><soap:body use="'.$opParts['input']['use'].'" namespace="'.$opParts['input']['namespace'].'" encodingStyle="'.$opParts['input']['encodingStyle'].'"/></input>';
					$xml .= '<output><soap:body use="'.$opParts['output']['use'].'" namespace="'.$opParts['output']['namespace'].'" encodingStyle="'.$opParts['output']['encodingStyle'].'"/></output>';
					$xml .= '</operation>';
				}
				$xml .= '</binding>';
			}
		}
		// services
		$xml .= '<service name="'.$this->serviceName.'">';
		if(count($this->ports) >= 1){
			foreach($this->ports as $pName => $attrs){
				$xml .= '<port name="'.$pName.'" binding="'.$attrs['binding'].'">';
				$xml .= '<soap:address location="'.$attrs['location'].'"/>';
				$xml .= '</port>';
			}
		}
		$xml .= '</service>';
		return $xml.'</definitions>';
	}

    /**
	* serialize the parsed wsdl
	*
    * @return string, serialization of WSDL
	* @access   public
	*/
	function serialize2(){
		$xml = '<?xml version="1.0"?><definitions';
		foreach($this->namespaces as $k => $v){
			$xml .= " xmlns:$k=\"$v\"";
		}
		$xml .= '>';

		// imports
		if(sizeof($this->import) > 0){
			foreach($this->import as $ns => $url){
				$xml .= '<import location="'.$url.'" namespace="'.$ns.'" />';
			}
		}

		// types
		if($this->schema){
			$xml .= '<types>';
			$xml .= $this->serializeSchema();
			$xml .= '</types>';
		}

		// messages
		if(count($this->messages) >= 1){
			foreach($this->messages as $msgName => $msgParts){
				$xml .= '<message name="'.$msgName.'">';
				foreach($msgParts as $partName => $partType){
                	//print 'serializing '.$partType.', sv: '.$this->XMLSchemaVersion.'<br>';
                	if(strpos(':',$partType)){
                    	$typePrefix = $this->getPrefixFromNamespace($this->getPrefix($partType));
                    } elseif(isset($this->typemap[$this->namespaces['xsd']][$partType])){
                    	print 'checking typemap: '.$this->XMLSchemaVersion.'<br>';
                        $typePrefix = 'xsd';
                    } else {
                        foreach($this->typemap as $ns => $types){
                          	if(isset($types[$partType])){
                                $typePrefix = $this->getPrefixFromNamespace($ns);
                            }
                        }
                        if(!isset($typePrefix)){
                   	    	die("$partType has no namespace!");
                    	}
                    }
					$xml .= '<part name="'.$partName.'" type="'.$typePrefix.':'.$this->getLocalPart($partType).'" />';
				}
				$xml .= '</message>';
			}
		}

		// bindings
		if(count($this->bindings) >= 1){
			foreach($this->bindings as $bindingName => $attrs){

                $binding_xml .= '<binding name="'.$msgName.'" type="'.$attrs['type'].'">';
				$binding_xml .= "<soap:binding style=\"".$attrs['style'].'" transport="'.$attrs['transport'].'"/>';
				$portType_xml .= '<portType name="'.$portTypeName.'">';
                foreach($attrs["operations"] as $opName => $opParts){
					$binding_xml .= '<operation name="'.$opName.'">';
					$binding_xml .= '<soap:operation soapAction="'.$opParts['soapAction'].'"/>';
					$binding_xml .= '<input><soap:body use="'.$opParts['input']['use'].'" namespace="'.$opParts['input']['namespace'].'" encodingStyle="'.$opParts['input']['encodingStyle'].'"/></input>';
					$binding_xml .= '<output><soap:body use="'.$opParts['output']['use'].'" namespace="'.$opParts['output']['namespace'].'" encodingStyle="'.$opParts['output']['encodingStyle'].'"/></output>';
					$binding_xml .= '</operation>';

                    $portType_xml .= '<operation name="'.$opParts['name'].'"';
                    if(isset($opParts['parameterOrder'])){
                    	$portType_xml .= ' parameterOrder="'.$opParts['parameterOrder'].'"';
                    }
                    $portType_xml .= '>';
                    $portType_xml .= '<input message="'.$opParts['input']['message'].'"/>';
                    $portType_xml .= '<output message="'.$opParts['output']['message'].'"/>';
                    $portType_xml .= '</operation>';
				}
                $portType_xml .= '</portType>';
				$binding_xml .= '</binding>';

			}
            $xml .= $portType_xml.$binding_xml;
		}
		// services
		$xml .= '<service name="'.$this->serviceName.'">';
		if(count($this->ports) >= 1){
			foreach($this->ports as $pName => $attrs){
				$xml .= '<port name="'.$pName.'" binding="'.$attrs['binding'].'">';
				$xml .= '<soap:address location="'.$attrs['location'].'"/>';
				$xml .= '</port>';
			}
		}
		$xml .= '</service>';
		return $xml.'</definitions>';
	}

	/**
	* serialize a PHP value according to a WSDL message definition
	*
    * TODO
	* - multi-ref serialization
	* - validate PHP values against type definitions, return errors if invalid
    *
	* @param	string type name
	* @param	mixed param value
	* @return	mixed new param or false if initial value didn't validate
	*/
	function serializeRPCParameters($operation,$direction,$parameters){
		if($direction != 'input' && $direction != 'output'){
	    	$this->setError('The value of the \$direction argument needs to be either "input" or "output"');
			return false;
	    }
		if(!$opData = $this->getOperationData($operation)){
        	$this->setError('Unable to retrieve WSDL data for operation: '.$operation);
			return false;
		}
		$this->debug( 'in serializeRPCParameters with xml schema version '.$this->XMLSchemaVersion);
		// set input params
        $xml = '';
		if(sizeof($opData[$direction]['parts']) > 0){
        	$this->debug('got '.count($opData[$direction]['parts']).' part(s)');
			foreach($opData[$direction]['parts'] as $name => $type){
            	if(isset($parameters[$name])){
                	$xml .= $this->serializeType($name,$type,$parameters[$name]);
                } else {
					$xml .= $this->serializeType($name,$type,array_shift($parameters));
                }
			}
		}
		return $xml;
	}

    /**
    * serializes a PHP value according a given type definition
    *
    * @param string $name, name of type
    * @param string $type, type of type, heh
    * @param mixed $value, a native PHP value
    * @return string serialization
    * @access public
    */
    function serializeType($name,$type,$value){
    	$contents = '';
    	$this->debug("in serializeType: $name, $type, $value");
		if(strpos($type,':')){
			$uqType = substr($type,strrpos($type,':')+1);
	    	$ns = substr($type,0,strrpos($type,':'));
	    	$this->debug("got a prefixed type: $uqType, $ns");
	    	if($ns == $this->XMLSchemaVersion){
	    		if($uqType == 'boolean' && !$value){
					$value = 0;
				} elseif($uqType == 'boolean'){
					$value = 1;
				}
				if($this->charencoding && $uqType == 'string' && gettype($value) == 'string'){
					$value = htmlspecialchars($value);
				}
				// it's a scalar
				return "<$name xsi:type=\"".$this->getPrefixFromNamespace($this->XMLSchemaVersion).":$uqType\">$value</$name>\n";
	    	}
		} else {
			$uqType = $type;
		}
		$typeDef = $this->getTypeDef($uqType);
        foreach($typeDef as $k => $v){
        	$this->debug("typedef, $k: $v");
        }
		$phpType = $typeDef['phpType'];
		$this->debug("serializeType: uqType: $uqType, ns: $ns, phptype: $phpType, arrayType: ".$typeDef['arrayType']);
		// if php type == struct, map value to the <all> element names
		if($phpType == 'struct'){
	    	$xml = "<$name xsi:type=\"".$this->getPrefixFromNamespace($ns).":$uqType\">\n";
	    	if(is_array($this->complexTypes[$uqType]['elements'])){
				foreach($this->complexTypes[$uqType]['elements'] as $eName => $attrs){
					// get value
					if(isset($value[$eName])){
						$v = $value[$eName];
					} elseif(is_array($value)) {
						$v = array_shift($value);
					}
					if(!isset($attrs['type'])){
						$xml .= $this->serializeType($eName,$attrs['name'],$v);
					} else {
						$this->debug("calling serialize_val() for $eName, $v, ".$this->getLocalPart($attrs['type']));
						$xml .= $this->serialize_val($v,$eName,$this->getLocalPart($attrs['type']),null,$this->getNamespaceFromPrefix($this->getPrefix($attrs['type'])));
					}
				}
	    	}
	    	$xml .= "</$name>\n";
		} elseif($phpType == 'array'){
			$rows = sizeof($value);
	    	if(isset($typeDef['multidimensional'])){
	    		$nv = array();
				foreach($value as $v){
					$cols = ','.sizeof($v);
		    		$nv = array_merge($nv,$v);
				}
				$value = $nv;
            } else {
            	$cols = '';
            }
			if(is_array($value) && sizeof($value) >= 1){
	    		foreach($value as $k => $v){
					if(strpos($typeDef['arrayType'],':')){
						$contents .= $this->serializeType('item',$typeDef['arrayType'],$v);
					} else {
						$contents .= $this->serialize_val($v,'item',$typeDef['arrayType'],null,$this->XMLSchemaVersion);
					}
	    		}
			}
			$xml = "<$name xsi:type=\"".$this->getPrefixFromNamespace('http://schemas.xmlsoap.org/soap/encoding/').':Array" '.
			$this->getPrefixFromNamespace('http://schemas.xmlsoap.org/soap/encoding/')
			.':arrayType="'
			.$this->getPrefixFromNamespace($this->getPrefix($typeDef['arrayType']))
			.":".$this->getLocalPart($typeDef['arrayType'])."[$rows$cols]\">\n"
			.$contents
			."</$name>\n";
		}
    	return $xml;
	}

    /**
	* register a service with the server
	*
	* @param    string $methodname
	* @param    string $in assoc array of input values: key = param name, value = param type
	* @param    string $out assoc array of output values: key = param name, value = param type
	* @param	string $namespace
	* @param	string $soapaction
	* @param	string $style (rpc|literal)
	* @access   public
	*/
	function addOperation($name,$in=false,$out=false,$namespace=false,$soapaction=false,$style='rpc',$use='encoded',$documentation=''){
        if($style == 'rpc' && $use == 'encoded'){
        	$encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/';
        } else {
        	$encodingStyle = '';
        }

		// get binding
        $this->bindings[ $this->serviceName.'Binding' ]['operations'][$name] =
        array(
        'name' => $name,
        'binding' => $this->serviceName.'Binding',
        'endpoint' => $this->endpoint,
        'soapAction' => $soapaction,
        'style' => $style,
        'input' => array(
            'use' => $use,
            'namespace' => $namespace,
            'encodingStyle' => $encodingStyle,
            'message' => $name.'Request',
            'parts' => $in),
        'output' => array(
            'use' => $use,
            'namespace' => $namespace,
            'encodingStyle' => $encodingStyle,
            'message' => $name.'Response',
            'parts' => $out),
        'namespace' => $namespace,
        'transport' => 'http://schemas.xmlsoap.org/soap/http',
        'documentation' => $documentation);
        // add portTypes
        // add messages
        if($in){
        	foreach($in as $pName => $pType){
        		$this->messages[$name.'Request'][$pName] = $pType;
        	}
        }
        if($out){
        	foreach($out as $pName => $pType){
        		$this->messages[$name.'Response'][$pName] = $pType;
        	}
        }
        return true;
	}
}

?>
