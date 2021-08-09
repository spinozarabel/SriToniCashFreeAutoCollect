<?php
/* Modified by Madhu Avasarala 06/28/2021
* ver 1.8 cleaned up code a little
* ver 1.7 added change active status of VPA
* ver 1.6 added params to getcurl
* ver 1.5 added prod_cosnt as variable and not a constant
* ver 1.4 make the site settings generic instead of hset, etc.
* ver 1.3 add Moodle and WP compatibility and get settings appropriately
*         all data returned as objects instead of arrays in json_decode
*/

// if directly called with both unset, die. If any one is set then proceed internally
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins
class CfAutoCollect
{
    protected $token;
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
	protected $siteName;
    protected $config;

    //const TEST_PRODUCTION  = "TEST";
    const VERBOSE          = false;

    public function __construct($site_name = null, $verbose = null)
    {
        $this->verbose      = $verbose;

        if ( defined("ABSPATH") )
		{
			// we are in wordpress environment, don't care about $site_name since get_option is site dependendent
            // ensure key and sercret set correctly no check is made wether set or not
            // Make sure these work for Virtual Account API
			//$api_key		= $this->getoption("sritoni_settings", "cashfree_key");
			//$api_secret		= $this->getoption("sritoni_settings", "cashfree_secret");

            $this->get_config_wp();

            $api_key    = $this->config['api_key'];
            $api_secret = $this->config['api_secret'];

            $stage          = $this->getoption("sritoni_settings", "production") ?? 0;
		}

        if ( defined("MOODLE_INTERNAL") )
    		{
    			// we are in MOODLE environment
    			// based on passed in $site_name change the strings for config select.
                // $site must be passed correctlt for this to work, no check is made
                // make sure these definitions are same as in configurable_reports plugin settings
    			// read in the site names defined in the settings as a comma separated string of 2 site names
    			// for example $sitenames_arr[0] = "hset-payments", [1] = "hsea-llp-payments"
    			$sitenames_arr = explode( "," , get_config('block_configurable_reports', 'site_names') );
    			// we will check the passed in $site_name variable to see which item in the array equals it
                if ($site_name == $sitenames_arr[0])
                {
                    $key_string 	= 'pg_api_key_site1';
                    $secret_string 	= 'pg_api_secret_site1';
                }
                elseif ( count($sitenames_arr) > 1 )
                {
                    if ($site_name == $sitenames_arr[1])
                    {
                        $key_string 	= 'pg_api_key_site2';
    					$secret_string 	= 'pg_api_secret_site2';
                    }
                }
                else
                {
                    error_log('Site name passed: ' . $site_name . ' is not in list of sites from config settings');
                    error_log(print_r($sitenames_arr, true));
                    throw new Exception("Could not get API credentials since site name passed does not match values in settings");
                }
                // this get_config is Moodle's function not to be confused with WP method defined below
    			$api_key		= get_config('block_configurable_reports', $key_string);
    			$api_secret		= get_config('block_configurable_reports', $secret_string);
                $stage          = get_config('block_configurable_reports', 'production'); // production or test
    		}

        // add these as properties of object
        $this->clientId		= $api_key;
		$this->clientSecret	= $api_secret;
		$this->site_name	= $site_name;

        if ($stage)     // set base URL or API based on if setting is production or test
            {
              // we are in production environment
              $this->baseUrl = "https://cac-api.cashfree.com/cac/v1";
            }
        else
            {
              // we are in test environment
              $this->baseUrl = "https://cac-gamma.cashfree.com/cac/v1";
            }

        $this->token     = $this->authorizeAndGetToken();
    }       // end construct function

    // function to read in the configuration file for WP case
    private function get_config_wp()
    {
      $this->config = include( __DIR__."/sritonicashfree_config.php");
    }

	/**
	*  @param optionGroup is the group for the settings
	*  @param optionField is the serring field within the group
	*  returns the value of the setting specified by the field in the settings group
	*/
	public function getoption($optionGroup, $optionField)
	{
		return get_option( $optionGroup)[$optionField];
	}

    /**
    *  authenticates to pg server using key and secret
    *  returns the token to be used for further authentication
    */
    protected function authorizeAndGetToken()
    {
        $token              = null;                     // initialize to null
        $clientId           = $this->clientId;
        $clientSecret       = $this->clientSecret;

        $headers =
        [
         "X-Client-Id: $clientId",
         "X-Client-Secret: $clientSecret"
        ];

        $endpoint       = $this->baseUrl."/authorize";
        $curlResponse   = $this->postCurl($endpoint, $headers);
        if ($curlResponse)
        {
           if ($curlResponse->status == "SUCCESS")
           {
             $token = $curlResponse->data->token;
             return $token;
           } else
           {
              throw new Exception("Authorization failed. Reason : ". $curlResponse->message);
           }
        }
    }       // end of function authorizeAndGetToken

    /**
    * @param vAccountId is moodle id padded if needed for min 4 chars
    * @param name is the user's sritoni full name
    * @param phone is the user's principal phone number
    * @param email is the SriToni email of user
    * returns a valid object if successfull, otherwise null
    */
    public function createVirtualAccount($vAccountId, $name, $phone, $email)
    {
        // Not checkin for valid token, responsibility of programmer to ensure this
        $endpoint   = $this->baseUrl."/createVA";
        $authToken  = $this->token;
        $headers    = [
            "Authorization: Bearer $authToken"
            ];
        // pad moodleuserid with 0's from left for minimum length of 4
        // $vAccountId = str_pad($moodleuserid, 4, "0", STR_PAD_LEFT);
        $params     = array
                            (
                                "vAccountId" => $vAccountId,
                                "name"       => $name,
                                "phone"      => $phone,
                                "email"      => $email,
                            );
        $curlResponse = $this->postCurl($endpoint, $headers, $params);
        //error_log("curl response of accountcreate");
        //error_log(print_r($curlResponse));
        if ($curlResponse->status == "SUCCESS")
            {
                return $curlResponse->data; // returns new account object
            }
        else
            {
                error_log( "This is the error message while creating a new Virtual Account" . $curlResponse->message );

                return null;
            }
  }           // end of function createVirtualAccount

    /**
    * returns an object with all vAccounts created so far
    * The data is an array numerically indexed, of objects
    */
    function listAllVirtualAccounts()
    {
        if ($this->token)
        {
            $endpoint = $this->baseUrl."/allVA";
            $authToken = $this->token;
            $headers = [
                        "Authorization: Bearer $authToken"
                       ];
            $curlResponse   = $this->getCurl ($endpoint, $headers);
            if ($curlResponse->status == "SUCCESS")
            {
              $vAccounts = $curlResponse->data->vAccounts;
            }
            else 
            {
                error_log( "This is the error message while listing all VAs" . $curlResponse->message );
                $vAccounts = NULL;
            }
        }
        return $vAccounts;

    }       // end of function listAllVirtualAccounts

    /**
    * @param vAccountId is the Virtual account ID
    * @param vAccounts is the array containing list of all vAs
    * returns the boolean value of vA with this ID exists or not
    */
    function vAExists($vAccountId, $vAccounts)
    {
        if (sizeof($vAccounts) == 0)
        {
            // no entries in the given array
            return false;
        }
        // we have at least one entry in the array
        foreach ($vAccounts as $key => $vA)
        {
            if ( $vA->vAccountId == $vAccountId )
            {
                // Virtual Account exists with the given ID
                return true;
            }
        }
        // we have looped through entire list with no match
        return false;
    }

    /**
    *  Get Virtual Account Object given its ID
    * @param vAccountId is the vAccountId
    * returns null if not successfull
    * returns the fetched virtual account object if successfull
    */
    function getvAccountGivenId($vAccountId)
    {
        if (!$this->token)
            {
                return null;
            }
       
        // pad the moodle user id with 0's on left side, if less than 4 digits
        // $vAccountId = str_pad($moodleuserid, 4, "0", STR_PAD_LEFT);
        $endpoint = $this->baseUrl . "/va/" . $vAccountId;
        $authToken = $this->token;
        $headers = [
                    "Authorization: Bearer $authToken"
                   ];
        $curlResponse = $this->getCurl($endpoint, $headers);

        if ($curlResponse->status == "SUCCESS")
        {
          $vA = $curlResponse->data;    // return the account details object
        }
        else 
        {
            error_log( "This is the error message trying to get Vaccount" . $curlResponse->message );
            
            $vA = null;
        }
        return $vA;
    }


    /**
     * 
     */
    public function getPaymentById($payment_id)
    {
        $params   = NULL;

        $endpoint = $this->baseUrl."/fetchPaymentById/".$payment_id;
        $authToken = $this->token;
        $headers = [
                    "Authorization: Bearer $authToken"
                    ];
        $curlResponse = $this->getCurl($endpoint, $headers, $params);

        if ($curlResponse->status == "SUCCESS")
        {
            $payment = $curlResponse->data->payment;
        }
        else
        {
            error_log( "This is the error message trying to get payment by ID" . $curlResponse->message );

            $payment = NULL;   // return null if not successfull
        }

        return $payment;
    }



    /**
    *  @param vAccountId is self explanatory, is SriToni ID number limited to 8 chars
    *  @param maxReturn is the maximum number of payments to be reurned
    *  returns a total of <= maxReturn payments made to this account as an array of payment objects
    */
    public function getPaymentsForVirtualAccount($vAccountId, $maxReturn = null)
    {
        
        $params   = NULL;

        $endpoint = $this->baseUrl."/payments/".$vAccountId;
        $authToken = $this->token;
        $headers = [
             "Authorization: Bearer $authToken"
              ];

        if ($maxReturn)
            {
                // check to see if max numoer of payments to be returned, is specified
                // if so construct params array
                $params = array(
                                'maxReturn' =>  $maxReturn,
                                );
            }

        $curlResponse = $this->getCurl($endpoint, $headers, $params);

        if ($curlResponse->status == "SUCCESS")
        {
            $payments = $curlResponse->data->payments;
        }
        else
        {
            error_log( "This is the error message trying to get payments of Vaccount" . $curlResponse->message );

            $payments = NULL;   // return null if not successfull
        }

        return $payments;
    }

    /**
    * @param vAccountId is moodle id padded if needed for min 4 chars
    *
    * returns Response{"status": "SUCCESS", "subCode": "200",
    *                  "message": "Vitual account status updated succesfully"}
    */
    public function deactivateVA($vAccountId)
    {
        // Not checkin for valid token, responsibility of programmer to ensure this
        $endpoint   = $this->baseUrl."/changeVAStatus";
        $authToken  = $this->token;
        $headers    = [
            "Authorization: Bearer $authToken"
            ];
        // pad moodleuserid with 0's from left for minimum length of 4
        // $vAccountId = str_pad($moodleuserid, 4, "0", STR_PAD_LEFT);
        $params     = array
                            (
                                "vAccountId" => $vAccountId,
                                "status"     => "INACTIVE",
                            );
    }


    protected function postCurl ($endpoint, $headers, $params = []) {
      $postFields = json_encode($params);
      array_push($headers,
         'Content-Type: application/json',
         'Content-Length: ' . strlen($postFields));


      $endpoint = $endpoint."?";
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $endpoint);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

      $returnData = curl_exec($ch);
      curl_close($ch);
      if ($returnData != "") {
        return json_decode($returnData, false);     // returns object not array
      }
      return NULL;
    }

    /**
    *  @param endpoint is the full path url of endpoint, not including any parameters
    *  @param headers is the array conatining a single item, the bearer token
    *  @param params is the optional array containing the get parameters
    */
    protected function getCurl ($endpoint, $headers, $params = [])
    {
        // check if anything exists in $params. If so make a query string out of it
       if ($params)
        {
           if ( count($params) )
           {
               $endpoint = $endpoint . '?' . http_build_query($params);
           }
        }
       $ch = curl_init();
       curl_setopt($ch, CURLOPT_URL, $endpoint);
       curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
       curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1); // verifies the authenticity of the peer's certificate
       curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // verify the certificate's name against host
       $returnData = curl_exec($ch);
       curl_close($ch);
       if ($returnData != "") {
        return json_decode($returnData, false);     // returns object not array
       }
       return NULL;
    }

    function __destruct()
    {
      $this->token = NULL;
    }

    /**
    *  returns the client secret of the api
    */
    public function get_clientSecret()
    {
        return $this->clientSecret;
    }
}       // class definition ends
?>
