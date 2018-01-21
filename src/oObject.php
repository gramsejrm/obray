<?php
    /**
     * @license MIT
     */

    namespace obray;

    /**
     * This class is the foundation of an obray based application
     */

    Class oObject {

        /** @var int Records the start time (time the object was created).  Cane be used for performance tuning */
        private $starttime;
        /** @var bool indicates if there was an error on this object */
        private $is_error = FALSE;
        /** @var int Status code - used to translate to HTTP 1.1 status codes */
        private $status_code = 200;
        /** @var int Stores the content type of this class or how it should be represented externally */
        private $content_type = 'application/json';
        /** @var int Stores information about a connection or the connection itself for the purpose of establishing a connection to DB */
        protected $oDBOConnection;
        /** @var \Psr\Container\ContainerInterface Stores the objects container object for dependency injection */
        protected $container;
        /** @var \obray\oFactoryInterface Stores the objects factory object for the factory method */
        protected $factory;
        /** @var \obray\oInvokerInterface Stores the objects factory object for the factory method */
        protected $invoker;
        /** @var bool specify if we are in debug mode or not */
        protected $debug_mode = false;
        /** @var string the users table */
        protected $user_session_key = "oUser";

        /** @var string stores the name of the class */
        public $object = '';

        /**
         * The route method takes a path and converts it into an object/and or 
         * method.
         *
         * @param string $path A path to an object/method
         * @param array $params An array of parameters to pass to the method
         * @param bool $direct Specifies if the route is being called directly
         * 
         * @return \obray\oObject
         */

        public function route( $path , $params = array(), $direct = TRUE ) {

            if( !$direct ){
                $params = array_merge($params,$_GET,$_POST); 
            }

            $components = parse_url($path); $this->components = $components;
            if( isSet($components['query']) ){
                parse_str($components['query'],$tmp_params);
                $params = array_merge($tmp_params,$params);
            }

            $path_array = explode('/',$components['path']);
            $path_array = array_filter($path_array);
            $path_array = array_values($path_array);

            if( isSet($components['host']) && $direct ){
                if (!class_exists( 'obray\oCURL' )) { 
                    throw \Exception("obray\oCURL is not defined/installed.",500)
                }
                $this->data = new obray\oCURL($components);
                return $this;
            }

            if( $direct === FALSE ){
                $this->validateRemoteApplication($direct);
            }

            // set content type with these special parameters
            if( isset($params['ocsv']) ){ $this->setContentType('text/csv'); unset($params['ocsv']); }
            if( isset($params['otsv']) ){ $this->setContentType('text/tsv'); unset($params['otsv']); }
            if( isset($params['otable']) ){ $this->setContentType('text/table'); unset($params['otable']); }

            // use the factory and invoker to create an object invoke its methods
            try {
                $this->checkPermissions($obj,null,$direct);
                $obj = $this->factory->make('\\' . implode('\\',$path_array));
                if( method_exists($obj,"index") ){
                    $this->checkPermissions($obj,"index",$direct);
                    return $this->invoker->invoke($obj,"index",$params);
                }
                return $obj;
            } catch( ClassNotFound $e ) {
                $function = array_pop($path_array);
                $this->checkPermissions($obj,null,$direct);
                $obj = $this->factory->make('\\' . implode('\\',$path_array));
                $this->checkPermissions($obj,$function,$direct);
                return $this->invoker->invoke($obj,$function,$params);
            }

            // if we're unsuccessful in anything above then throw error
            throw \Exception("Could not find " . $components['path'],404);
            return $this;

        }

        /**
         * This method checks to see if the remote call has permissions to access
         * routes.  If so full permissions granted by setting direct to true
         *
         * @param bool $direct Determines if application is being called from a remote source
         * 
         */

        public function validateRemoteApplication(&$direct){
            $headers = getallheaders();
            if( isSet($headers['Obray-Token']) ){
                $otoken = $headers['Obray-Token']; unset($headers['Obray-Token']);
                if( defined('__OBRAY_TOKEN__') && $otoken === __OBRAY_TOKEN__ && __OBRAY_TOKEN__ != '' ){ $direct = true;  }
            }
        }

        /**
         * This method checks the pmerissions set on the object and allows permissions
         * accordingly
         *
         * @param mixed $obj The object we are going to check permissions on
         * @param bool $direct Specifies if the call is from a remote source
         * 
         */

        protected function checkPermissions($obj,$direct){
            if( $direct ){ return; }
                $perms = $obj->getPermissions();
                if( !isSet($perms[$object_name]) ){
                throw \Exception('You cannot access this resource.',403)
            } else if ( isSet($perms[$object_name]) && $perms[$object_name] !== 'any' ){
                throw \Exception('You cannot access this resource.',403)
            }
        }

        /**
         * the cleanUp method removes class properties that we don't want output
         */

        public function cleanUp(){
            if( !in_array($this->content_type,['text/csv','text/tsv','text/table']) ){

                //     1)     remove all object keys not white listed for
                            //         output - this is so we don't expose unnecessary
                            //              information

                $keys = ['object','errors','data','runtime','html','recordcount'];

                //    2)    if in debug mode allow some additiona information
                //        through

                if( $this->debug_mode ){
                    $keys[] = 'sql'; $keys[] = 'filter'; 
                }

                //    3)    based on our allowed keys unset valus from public
                //        data members

                foreach($this as $key => $value) {
                    if( !in_array($key,$keys) ){
                        unset($this->$key);
                    }
                }

            }
        }

        /***********************************************************************

            ERROR HANDLING FUNCTIONS

            //    1)    Throw Error
            //        a)    Set is_error to TRUE
            //        b)    initialize this->errors if not intialized
            //        c)    add error of type to errors array
            //        d)    set status code

            //    2)    Is Error: returns TRUE/FALSE if error on object

            //    3)    Get Stack Trace

        ***********************************************************************/

        ////////////////////////////////////////////////////////////////////////
        //
        //    1)    Throw Error
                //        a)    Set is_error to TRUE
                //        b)    initialize this->errors if not intialized
                //        c)    add error of type to errors array
                //        d)      set status code
        //
        ////////////////////////////////////////////////////////////////////////

        public function throwError($message,$status_code=500,$type='general'){

            //              a)      Set is_error to TRUE
            $this->is_error = TRUE;
            //              b)      initialize this->errors if not intialized
            if( empty($this->errors) || !is_array($this->errors) ){
                $this->errors = [];
            }
            //              c)      add error of type to errors array
                $this->errors[$type][] = $message;
            //              d)      set status code
                $this->status_code = $status_code;

            }

        ////////////////////////////////////////////////////////////////////////
        //
        //      2)      Is Error: returns TRUE/FALSE if error on object
        //
        ////////////////////////////////////////////////////////////////////////

        public function isError(){
            return $this->is_error;
        }

        ////////////////////////////////////////////////////////////////////////
        //
        //      3)      Get Stack Trace
        //
        ////////////////////////////////////////////////////////////////////////

        public function getStackTrace($exception) {

            $stackTrace = "";
            $count = 0;
            foreach ($exception->getTrace() as $frame) {
                $args = "";
                if (isset($frame['args'])) {
                    $args = array();
                    foreach ($frame['args'] as $arg) {
                        if (is_string($arg)) {
                            $args[] = "'" . $arg . "'";
                        } elseif (is_array($arg)) {
                            $args[] = "Array";
                        } elseif (is_null($arg)) {
                            $args[] = 'NULL';
                        } elseif (is_bool($arg)) {
                            $args[] = ($arg) ? "true" : "false";
                        } elseif (is_object($arg)) {
                            $args[] = get_class($arg);
                        } elseif (is_resource($arg)) {
                            $args[] = get_resource_type($arg);
                        } else {
                            $args[] = $arg;
                        }
                    }
                    $args = join(", ", $args);
                }
                $stackTrace .= sprintf( "#%s %s(%s): %s(%s)\n",
                    $count,
                    $frame['file'],
                    $frame['line'],
                    $frame['function'],
                    $args );
                $count++;
            }
            return $stackTrace;

        }

        /***********************************************************************

            ROLES & PERMISSIONS FUNCTIONS

        ***********************************************************************/

        public function hasRole( $code ){
            if( ( !empty($_SESSION['ouser']->roles) && in_array($code,$_SESSION["ouser"]->roles) ) || ( !empty($_SESSION["ouser"]->roles) && in_array("SUPER",$_SESSION["ouser"]->roles) ) ){
                return TRUE;
            }
            return FALSE;
        }

        public function errorOnRole( $code ){
            if( !$this->hasRole($code) ){
                $this->throwError( "Permission denied", 403 );
                return true;
            }
            return false;
        }

        public function hasPermission( $code ){
            if( ( !empty($_SESSION['ouser']->permissions) && in_array($code,$_SESSION["ouser"]->permissions) ) || ( !empty($_SESSION["ouser"]->roles) && in_array("SUPER",$_SESSION["ouser"]->roles) ) ){
                return TRUE;
            }
            return FALSE;
        }

        public function errorOnPermission( $code ){
            if( !$this->hasPermission($code) ){
                $this->throwError( "Permission denied", 403 );
                return true;
            }
            return false;
        }

        /***********************************************************************

            GETTER AND SETTER FUNCTIONS

        ***********************************************************************/

        private function setObject($obj){ $this->object = $obj;}
        public function getStatusCode(){ return $this->status_code; }
        public function setStatusCode($code){ $this->status_code =; }
        public function getContentType(){ return $this->content_type; }
        public function setContentType($type){ if($this->content_type != 'text/html'){ $this->content_type = $type; } }
        public function getPermissions(){ return isset($this->permissions) ? $this->permissions : array(); }
        public function redirect($location="/"){ header( 'Location: '.$location ); die(); }
        
        /***********************************************************************

            RUN ROUTE IN BACKGROUND

        ***********************************************************************/

        public function routeBackground( $route ){
            shell_exec("php -d memory_limit=-1 ".__SELF__."tasks.php \"".$route."\" > /dev/null 2>&1 &");
        }

        /***********************************************************************

            LOGGING FUNCTIONS

        ***********************************************************************/

        public function logError($oProjectEnum, Exception $exception, $customMessage="") {
            $logger = new oLog();
            $logger->logError($oProjectEnum, $exception, $customMessage);
            return;
        }

        public function logInfo($oProjectEnum, $message) {
            $logger = new oLog();
            $logger->logInfo($oProjectEnum, $message);
            return;
        }

        public function logDebug($oProjectEnum, $message) {
            $logger = new oLog();
            $logger->logDebug($oProjectEnum, $message);
            return;
        }

        public function console(){

            $args = func_get_args();
            if( PHP_SAPI === 'cli' && !empty($args) ){

                if( is_array($args[0]) || is_object($args[0]) ) {
                    print_r($args[0]);
                } else if( count($args) === 3 && $args[1] !== NULL && $args[2] !== NULL ){
                    $colors = array(
                        // text color
                        "Black" =>            "\033[30m",
                        "Red" =>             "\033[31m",
                        "Green" =>            "\033[32m",
                        "Yellow" =>             "\033[33m",
                        "Blue" =>             "\033[34m",
                        "Purple" =>             "\033[35m",
                        "Cyan" =>            "\033[36m",
                        "White" =>             "\033[37m",
                        // text color bold
                        "BlackBold" =>             "\033[30m",
                        "RedBold" =>             "\033[1;31m",
                        "GreenBold" =>             "\033[1;32m",
                        "YellowBold" =>         "\033[1;33m",
                        "BlueBold" =>             "\033[1;34m",
                        "PurpleBold" =>         "\033[1;35m",
                        "CyanBold" =>             "\033[1;36m",
                        "WhiteBold" =>             "\033[1;37m",
                        // text color muted
                        "RedMuted" =>             "\033[2;31m",
                        "GreenMuted" =>         "\033[2;32m",
                        "YellowMuted" =>         "\033[2;33m",
                        "BlueMuted" =>             "\033[2;34m",
                        "PurpleMuted" =>         "\033[2;35m",
                        "CyanMuted" =>             "\033[2;36m",
                        "WhiteMuted" =>         "\033[2;37m",
                        // text color underlined
                        "BlackUnderline" =>         "\033[4;30m",
                        "RedUnderline" =>         "\033[4;31m",
                        "GreenUnderline" =>         "\033[4;32m",
                        "YellowUnderline" =>         "\033[4;33m",
                        "BlueUnderline" =>         "\033[4;34m",
                        "PurpleUnderline" =>         "\033[4;35m",
                        "CyanUnderline" =>         "\033[4;36m",
                        "WhiteUnderline" =>         "\033[4;37m",
                        // text color blink
                        "BlackBlink" =>         "\033[5;30m",
                        "RedBlink" =>             "\033[5;31m",
                        "GreenBlink" =>         "\033[5;32m",
                        "YellowBlink" =>         "\033[5;33m",
                        "BlueBlink" =>             "\033[5;34m",
                        "PurpleBlink" =>         "\033[5;35m",
                        "CyanBlink" =>            "\033[5;36m",
                        "WhiteBlink" =>         "\033[5;37m",
                        // text color background
                        "RedBackground" =>         "\033[7;31m",
                        "GreenBackground" =>         "\033[7;32m",
                        "YellowBackground" =>         "\033[7;33m",
                        "BlueBackground" =>         "\033[7;34m",
                        "PurpleBackground" =>         "\033[7;35m",
                        "CyanBackground" =>         "\033[7;36m",
                        "WhiteBackground" =>         "\033[7;37m",
                        // reset - auto called after each of the above by default
                        "Reset"=>             "\033[0m"
                    );
                    $color = $colors[$args[2]];
                    printf($color.array_shift($args)."\033[0m",array_shift($args) );
                } else {
                    printf( array_shift($args),array_shift($args) );
                }
            }
        }

    }
?>