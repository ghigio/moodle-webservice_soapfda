<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * SOAP web service implementation classes and methods.
 *
 * @package    webservice_soapfda
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->dirroot/webservice/lib.php");
require_once 'Zend/Soap/Server.php';

/**
 * The Zend XMLRPC server but with a fault that returns debuginfo
 *
 * @package    webservice_soap
 * @copyright  2011 Jerome Mouneyrac
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 2.2
 */
class moodle_zend_soap_server extends Zend_Soap_Server {

    /**
     * Generate a server fault
     *
     * Note that the arguments are the reverse of those used by SoapFault.
     *
     * Moodle note: basically we return the faultactor (errorcode) and faultdetails (debuginfo)
     *
     * If an exception is passed as the first argument, its message and code
     * will be used to create the fault object if it has been registered via
     * {@Link registerFaultException()}.
     *
     * @link   http://www.w3.org/TR/soap12-part1/#faultcodes
     * @param  string|Exception $fault
     * @param  string $code SOAP Fault Codes
     * @return SoapFault
     */
    public function fault($fault = null, $code = "Receiver")
    {

        // Run the zend code that clean/create a soapfault.
        $soapfault = parent::fault($fault, $code);

        // Intercept any exceptions and add the errorcode and debuginfo (optional).
        $actor = null;
        $details = null;
        if ($fault instanceof Exception) {
            // Add the debuginfo to the exception message if debuginfo must be returned.
            $actor = $fault->errorcode;
            if (debugging() and isset($fault->debuginfo)) {
                $details = $fault->debuginfo;
            }
        }

        return new SoapFault($soapfault->faultcode,
                $soapfault->getMessage() . ' | ERRORCODE: ' . $fault->errorcode,
                $actor, $details);
    }

    /**
     * Handle a request
     *
     * NOTE: this is basically a copy of the Zend handle()
     *       but with $soap->fault returning faultactor + faultdetail
     *       So we don't require coding style checks within this method
     *       to keep it as similar as the original one.
     *
     * Instantiates SoapServer object with options set in object, and
     * dispatches its handle() method.
     *
     * $request may be any of:
     * - DOMDocument; if so, then cast to XML
     * - DOMNode; if so, then grab owner document and cast to XML
     * - SimpleXMLElement; if so, then cast to XML
     * - stdClass; if so, calls __toString() and verifies XML
     * - string; if so, verifies XML
     *
     * If no request is passed, pulls request using php:://input (for
     * cross-platform compatability purposes).
     *
     * @param DOMDocument|DOMNode|SimpleXMLElement|stdClass|string $request Optional request
     * @return void|string
     */
    public function handle($request = null)
    {
        if (null === $request) {
            $request = file_get_contents('php://input');
        }

        // Set Zend_Soap_Server error handler
        $displayErrorsOriginalState = $this->_initializeSoapErrorContext();

        $setRequestException = null;
        /**
         * @see Zend_Soap_Server_Exception
         */
        require_once 'Zend/Soap/Server/Exception.php';
        try {
            $this->_setRequest($request);
        } catch (Zend_Soap_Server_Exception $e) {
            $setRequestException = $e;
        }

        $soap = $this->_getSoap();

        ob_start();
        if($setRequestException instanceof Exception) {
            // Send SOAP fault message if we've catched exception
            $soap->fault("Sender", $setRequestException->getMessage());
        } else {
            try {
                $soap->handle($request);
            } catch (Exception $e) {
                $fault = $this->fault($e);
                $faultactor = isset($fault->faultactor) ? $fault->faultactor : null;
                $detail = isset($fault->detail) ? $fault->detail : null;
                $soap->fault($fault->faultcode, $fault->faultstring, $faultactor, $detail);
            }
        }
        $this->_response = ob_get_clean();

        // Restore original error handler
        restore_error_handler();
        ini_set('display_errors', $displayErrorsOriginalState);

        if (!$this->_returnResponse) {
            echo $this->_response;
            return;
        }

        return $this->_response;
    }
}

/**
 * SOAP service server implementation.
 *
 * @package    webservice_soap
 * @copyright  2009 Petr Skodak, 2012 Federico Ghigini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 2.0
 */
class webservice_soap_server extends webservice_zend_server {

    /**
     * Contructor
     *
     * @param string $authmethod authentication method of the web service (WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN, ...)
     */
    public function __construct($authmethod) {
         // must not cache wsdl - the list of functions is created on the fly
        ini_set('soap.wsdl_cache_enabled', '0');
        require_once 'Zend/Soap/Server.php';
        require_once 'Zend/Soap/AutoDiscover.php';

        if (optional_param('wsdl', 0, PARAM_BOOL)) {
            parent::__construct($authmethod, 'Zend_Soap_AutoDiscover');
        } else {
            parent::__construct($authmethod, 'moodle_zend_soap_server');
        }
        $this->wsname = 'soap';
    }

    /**
     * Set up zend service class
     */
    protected function init_zend_server() {
        global $CFG;

        parent::init_zend_server();

        if ($this->authmethod == WEBSERVICE_AUTHMETHOD_USERNAME) {
            $username = optional_param('wsusername', '', PARAM_RAW);
            $password = optional_param('wspassword', '', PARAM_RAW);
            // aparently some clients and zend soap server does not work well with "&" in urls :-(
            //TODO MDL-31151 the zend error has been fixed in the last Zend SOAP version, check that is fixed and remove obsolete code
            $url = $CFG->wwwroot.'/webservice/soapfda/simpleserver.php/'.urlencode($username).'/'.urlencode($password);
            // the Zend server is using this uri directly in xml - weird :-(
            $this->zend_server->setUri(htmlentities($url));
        } else {
            $wstoken = optional_param('wstoken', '', PARAM_RAW);
            $url = $CFG->wwwroot.'/webservice/soapfda/server.php?wstoken='.urlencode($wstoken);
            // the Zend server is using this uri directly in xml - weird :-(
            $this->zend_server->setUri(htmlentities($url));
        }

        if (!optional_param('wsdl', 0, PARAM_BOOL)) {
            $this->zend_server->setReturnResponse(true);
            $this->zend_server->registerFaultException('moodle_exception');
            $this->zend_server->registerFaultException('webservice_parameter_exception'); //deprecated since Moodle 2.2 - kept for backward compatibility
            $this->zend_server->registerFaultException('invalid_parameter_exception');
            $this->zend_server->registerFaultException('invalid_response_exception');
            //when DEBUG >= NORMAL then the thrown exceptions are "casted" into a PHP SoapFault expception
            //in order to diplay the $debuginfo (see moodle_zend_soap_server class - MDL-29435)
            if (debugging()) {
                $this->zend_server->registerFaultException('SoapFault');
            }
        } else {
            // Modified for patch MDL-28988
            require_once $CFG->libdir.'/zend/Zend/Soap/Wsdl/Strategy/ArrayOfTypeSequence.php';
            $strategy = new Zend_Soap_Wsdl_Strategy_ArrayOfTypeSequence();
            $this->zend_server->setComplexTypeStrategy($strategy);
        }
    }

    /**
     * This method parses the $_POST and $_GET superglobals and looks for
     * the following information:
     *  user authentication - username+password or token (wsusername, wspassword and wstoken parameters)
     */
    protected function parse_request() {
        parent::parse_request();

        if (!$this->username or !$this->password) {
            //note: this is the workaround for the trouble with & in soap urls
            $authdata = get_file_argument();
            $authdata = explode('/', trim($authdata, '/'));
            if (count($authdata) == 2) {
                list($this->username, $this->password) = $authdata;
            }
        }
    }

    /**
     * Send the error information to the WS client
     * formatted as an XML document.
     *
     * @param exception $ex the exception to send back
     */
    protected function send_error($ex=null) {

        if ($ex) {
            $info = $ex->getMessage();
            if (debugging() and isset($ex->debuginfo)) {
                $info .= ' - '.$ex->debuginfo;
            }
        } else {
            $info = 'Unknown error';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
<SOAP-ENV:Body><SOAP-ENV:Fault>
<faultcode>MOODLE:error</faultcode>
<faultstring>'.$info.'</faultstring>
</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        $this->send_headers();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: inline; filename="response.xml"');

        echo $xml;
    }

    /**
     * returns virtual method code
     *
     * @param stdClass $function a record from external_function
     * @return string PHP code
     */
    protected function get_virtual_method_code($function) {
        global $CFG;

        $function = external_function_info($function);

        //arguments in function declaration line with defaults.
        $paramanddefaults      = array();
        //arguments used as parameters for external lib call.
        $params      = array();
        $params_desc = array();
        foreach ($function->parameters_desc->keys as $name=>$keydesc) {
            $param = '$'.$name;
            $paramanddefault = $param;
            //need to generate the default if there is any
            if ($keydesc instanceof external_value) {
                if ($keydesc->required == VALUE_DEFAULT) {
                    if ($keydesc->default===null) {
                        $paramanddefault .= '=null';
                    } else {
                        switch($keydesc->type) {
                            case PARAM_BOOL:
                                $paramanddefault .= '='.$keydesc->default; break;
                            case PARAM_INT:
                                $paramanddefault .= '='.$keydesc->default; break;
                            case PARAM_FLOAT;
                                $paramanddefault .= '='.$keydesc->default; break;
                            default:
                                $paramanddefault .= '=\''.$keydesc->default.'\'';
                        }
                    }
                } else if ($keydesc->required == VALUE_OPTIONAL) {
                    //it does make sens to declare a parameter VALUE_OPTIONAL
                    //VALUE_OPTIONAL is used only for array/object key
                    throw new moodle_exception('parametercannotbevalueoptional');
                }
            } else { //for the moment we do not support default for other structure types
                 if ($keydesc->required == VALUE_DEFAULT) {
                     //accept empty array as default
                     if (isset($keydesc->default) and is_array($keydesc->default)
                             and empty($keydesc->default)) {
                         $paramanddefault .= '=array()';
                     } else {
                        throw new moodle_exception('errornotemptydefaultparamarray', 'webservice', '', $name);
                     }
                 }
                 if ($keydesc->required == VALUE_OPTIONAL) {
                     throw new moodle_exception('erroroptionalparamarray', 'webservice', '', $name);
                 }
            }
            $params[] = $param;
            $paramanddefaults[] = $paramanddefault;
            $type = $this->get_phpdoc_type($keydesc, $function->name.'__'.$name.'Data');
            $params_desc[] = '     * @param '.$type.' $'.$name.' '.$keydesc->desc;
        }
        $params                = implode(', ', $params);
        $paramanddefaults      = implode(', ', $paramanddefaults);
        $params_desc           = implode("\n", $params_desc);

        $serviceclassmethodbody = $this->service_class_method_body($function, $params);

        if (is_null($function->returns_desc)) {
            $return = '     * @return void';
        } else {
            $type = $this->get_phpdoc_return_type($function->returns_desc, $function->name.'__returnType');
            $return = '     * @return '.$type.' '.$function->returns_desc->desc;
        }

        // now crate the virtual method that calls the ext implementation

        $code = '
    /**
     * '.$function->description.'
     *
'.$params_desc.'
'.$return.'
     */
    public function '.$function->name.'('.$paramanddefaults.') {
'.$serviceclassmethodbody.'
    }
';
        return $code;
    }

    /**
     * Get the phpdoc type for an external_description
	 * Modified for MDL-28988: assign a unique name to the classes used for input parameters
     * external_value => int, double or string
     * external_single_structure => object|struct, on-fly generated stdClass name, ...
     * external_multiple_structure => array
     *
     * @param string $keydesc any of PARAM_*
     * @param string $method_name name of current method
     * @return string phpdoc type (string, double, int, array...)
     */
    protected function get_phpdoc_type($keydesc, $method_name='webservices_struct_class') {
        if ($keydesc instanceof external_value) {
            switch($keydesc->type) {
                case PARAM_BOOL: // 0 or 1 only for now
                case PARAM_INT:
                    $type = 'int'; break;
                case PARAM_FLOAT;
                    $type = 'double'; break;
                default:
                    $type = 'string';
            }

        } else if ($keydesc instanceof external_single_structure) {
            $classname = $this->generate_simple_struct_class($keydesc, $method_name);
            $type = $classname;

        } else if ($keydesc instanceof external_multiple_structure) {
            $classname = $this->generate_multiple_struct_class($keydesc, $method_name);
            $type = $classname;
        }

        return $type;
    }

    protected function get_phpdoc_return_type($keydesc, $method_name='webservices_struct_class') {
        if ($keydesc instanceof external_value) {
            switch($keydesc->type) {
                case PARAM_BOOL: // 0 or 1 only for now
                case PARAM_INT:
                    $type = 'int'; break;
                case PARAM_FLOAT;
                $type = 'double'; break;
                default:
                    $type = 'string';
            }

        } else if ($keydesc instanceof external_single_structure) {
            $classname = $this->generate_simple_struct_class($keydesc, $method_name);
            $type = $classname;

        } else if ($keydesc instanceof external_multiple_structure) {
            $type = $this->generate_multiple_struct_class($keydesc, $method_name);
        }

        return $type;
    }

    /**
     * Generate 'struct' type name
     * This type name is the name of a class generated on the fly.
     *
     * @param external_single_structure $structdesc
     * @param string $prefixname prefix for create object type
     * @return string
     */
    protected function generate_simple_struct_class(external_single_structure $structdesc, $prefixname='webservices_struct_class') {
        global $USER;
        // let's use unique class name, there might be problem in unit tests
		// MDL-28988
        $classname = $prefixname;

        $fields = array();
        foreach ($structdesc->keys as $name => $fieldsdesc) {
            $type = $this->get_phpdoc_type($fieldsdesc, $prefixname.'_'.$name);
            $fields[] = '    /** @var '.$type." */\n" .
                        '    public $'.$name.';';
        }

        $code = '
/**
 * Virtual struct class for web services for user id '.$USER->id.' in context '.$this->restricted_context->id.'.
 */
class '.$classname.' {
'.implode("\n", $fields).'
}
';
        eval($code);
        return $classname;
    }

	/**
     * MDL-28988: Generate 'array' type name
     * This type name is the name of an array generated on the fly.
     *
     * @param external_multiple_structure $structdesc
     * @param string $prefixname prefix for create array type
     * @return string
     */
    protected function generate_multiple_struct_class(external_multiple_structure $structdesc, $prefixname='webservices_struct_class') {
        global $USER;
        // let's use unique class name, there might be problem in unit tests
        $classname = $prefixname.'Array';

        if ($structdesc->content instanceof external_single_structure) {
            $myinnerclass = $this->generate_simple_struct_class($structdesc->content, $prefixname);

            return $myinnerclass.'[]';

        } else if ($structdesc->content instanceof external_value){
            switch($structdesc->content->type) {
                case PARAM_BOOL: // 0 or 1 only for now
                case PARAM_INT:
                    $type = 'int'; break;
                case PARAM_FLOAT;
                $type = 'double'; break;
                default:
                    $type = 'string';
            }

            return $type.'[]';
        } else {
            return 'array';
        }
    }

    /**
     * Get the generated web service function code.
     *
     * @param stdClass $function contains function name and class name
     * @param array $params all the function parameters
     * @return string the generate web service function code
     */
    protected function service_class_method_body($function, $params){
        //cast the param from object to array (validate_parameters except array only)
        $castingcode = '';
        if ($params){
            $paramstocast = explode(',', $params);
            foreach ($paramstocast as $paramtocast) {
                $paramtocast = trim($paramtocast);
                $castingcode .= $paramtocast .
                '=webservice_zend_server::cast_objects_to_array('.$paramtocast.');';
            }

        }

        $externallibcall = $function->classname.'::'.$function->methodname.'('.$params.')';
        $descriptionmethod = $function->methodname.'_returns()';
        $callforreturnvaluedesc = $function->classname.'::'.$descriptionmethod;
        return $castingcode .
        '        return webservice_soap_server::validate_and_cast_values('.$callforreturnvaluedesc.', '.$externallibcall.');';
    }

    /**
     * Validates submitted value, comparing it to a description. If anything is incorrect
     * invalid_return_value_exception is thrown. Also casts the values to the type specified in
     * the description.
     *
     * @param external_description $description description of parameters or null if no return value
     * @param mixed $value the actual values
     * @return mixed params with added defaults for optional items
     * @throws invalid_return_value_exception
     */
    public static function validate_and_cast_values($description, $value) {
        if (is_null($description)){
            return;
        }
        if ($description instanceof external_value) {
            if (is_array($value) or is_object($value)) {
                throw new invalid_return_value_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($value) or $value === 0 or $value === 1 or $value === '0' or $value === '1') {
                    return (bool)$value;
                }
            }
            return validate_param($value, $description->type, $description->allownull, 'Invalid external api parameter');

        } else if ($description instanceof external_single_structure) {
            if (!is_array($value)) {
                throw new invalid_return_value_exception('Only arrays accepted.');
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $value)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new invalid_return_value_exception('Missing required key in single structure: '.$key);
                    }
                    if ($subdesc instanceof external_value) {
                        if ($subdesc->required == VALUE_DEFAULT) {
                            $result[$key] = self::validate_and_cast_values($subdesc, $subdesc->default);
                        }
                    }
                } else {
                    $result[$key] = self::validate_and_cast_values($subdesc, $value[$key]);
                }
                unset($value[$key]);
            }

            return (object)$result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($value)) {
                throw new invalid_return_value_exception('Only arrays accepted.');
            }
            $result = array();
            foreach ($value as $param) {
                $result[] = self::validate_and_cast_values($description->content, $param);
            }
            return $result;

        } else {
            throw new invalid_return_value_exception('Invalid external api description.');
        }
    }
}

/**
 * SOAP test client class
 *
 * @package    webservice_soap
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 2.0
 */
class webservice_soap_test_client implements webservice_test_client_interface {

    /**
     * Execute test client WS request
     *
     * @param string $serverurl server url (including token parameter or username/password parameters)
     * @param string $function function name
     * @param array $params parameters of the called function
     * @return mixed
     */
    public function simpletest($serverurl, $function, $params) {
        //zend expects 0 based array with numeric indexes
        $params = array_values($params);
        require_once 'Zend/Soap/Client.php';
        $client = new Zend_Soap_Client($serverurl.'&wsdl=1');
        return $client->__call($function, $params);
    }
}
