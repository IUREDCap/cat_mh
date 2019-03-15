<?php
namespace VICTR\REDCAP\CAT_MH;

class CAT_MH extends \ExternalModules\AbstractExternalModule {
	// public $testAPI = false;
	public $testAPI = true;
	public $testTypes = [
		'mdd' => "Major Depressive Disorder",
		'dep' => "Depression",
		'anx' => "Anxiety Disorder",
		'mhm' => "Mania/Hypomania",
		'pdep' => "Depression (Perinatal)",
		'panx' => "Anxiety Disorder (Perinatal)",
		'pmhm' => "Mania/Hypomania (Perinatal)",
		'sa' => "Substance Abuse",
		'ptsd' => "Post-Traumatic Stress Disorder",
		'cssrs' => "C-SSRS Suicide Screen",
		'ss' => "Suicide Scale"
	];
	
	// hooks
	public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		// provide button for user to click to send them to interview page after they've read the last page of the survey submission document
		$input = [];
		$input['instrument'] = $instrument;
		$input['recordID'] = $record;
		$out = $this->createInterviews($input);
		// echo(json_encode($out));
		if (isset($out['moduleError'])) $this->log('catmhError', ['output' => $out]);
		
		if ($out !== false) {
			if ($instrument == $out['config']['instrumentRealName']) {
				echo("Click to begin your CAT-MH screening interview.<br />");
				$page = $this->getUrl("interview.php") . "&rid=" . $record . "&sid=" . $out['config']['subjectID'];
				echo("
				<button id='catmh_button'>Begin Interview</button>
				<script>
					var btn = document.getElementById('catmh_button')
					btn.addEventListener('click', function() {
						window.location.assign('$page');
					})
				</script>
				");
			} else {
				echo("There was an error in creating your CAT-MH interview:<br />");
				if (isset($out['moduleError'])) echo($out['moduleMessage'] . "<br />");
				echo("Please contact your REDCap system administrator.");
			}
		}
	}
	
	// utility
	public function getInterviewConfig($instrumentName) {
		// given the instrument name, we create and return an array that we will turn into JSON and send to CAT-MH to request createInterviews
		$config = [];
		$pid = $this->getProjectId();
		$projectSettings = $this->getProjectSettings();
		$result = $this->query('select form_name, form_menu_description from redcap_metadata where form_name="' . $instrumentName . '" and project_id=' . $pid . ' and form_menu_description<>""');
		$record = db_fetch_assoc($result);
		foreach ($projectSettings['survey_instrument']['value'] as $settingsIndex => $instrumentDisplayName) {
			if ($instrumentDisplayName == $record['form_menu_description']) {
				// create random subject ID
				$subjectID = "";
				$sidDomain = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
				$domainLength = strlen($sidDomain);
				for ($i = 0; $i < 32; $i++) {
					$subjectID .= $sidDomain[rand(0, $domainLength - 1)];
				}
				
				// tests array
				$tests = [];
				$testTypeKeys = array_keys($this->testTypes);
				foreach ($testTypeKeys as $j => $testAbbreviation) {
					if ($projectSettings[$testAbbreviation]['value'][$settingsIndex] == 1) {
						$tests[] = ["type" => $testAbbreviation];
					}
				}
				
				$config['subjectID'] = $subjectID;
				$config['tests'] = $tests;
				$config['organizationid'] = $this->getSystemSetting('organizationid');
				$config['applicationid'] = $this->getSystemSetting('applicationid');
				$config['instrumentDisplayName'] = $instrumentDisplayName;
				$config['instrumentRealName'] = $record['form_name'];
				$config['language'] = $projectSettings['language']['value'][$settingsIndex] == 2 ? 2 : 1;
				return $config;
			}
		}
		return false;
	}
	
	public function getInterviews($args) {
		$subjectID = $args['subjectID'];
		$result = $this->queryLogs("select subjectID, recordID, interviewID, status, timestamp, instrument, identifier, signature, type, label, AWSELB, JSESSIONID
			where subjectID='$subjectID' order by timestamp desc");
		$interviews = [];
		while($row = db_fetch_assoc($result)) {
			$interviews[] = $row;
		}
		return $interviews;
	}
	
	public function curl($args) {
		// required args:
		// address
		
		// optional args:
		// post, headers, body
		
		// initialize return/output array
		$output = [];
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $args['address']);
		if (isset($args['headers'])) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $args['headers']);
		}
		if (isset($args['post'])) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
		}
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$output['response'] = curl_exec($ch);
		$output['info'] = curl_getinfo($ch);
		$rawHeaders = substr($output['response'], 0, $output['info']['header_size']);
		$output['body'] = substr($output['response'], $output['info']['header_size']);
		$output['errorNumber'] = curl_errno($ch);
		$output['error'] = curl_error($ch);
		curl_close ($ch);
		
		// get headers as arrays
		function extractHeaders($headerContent) {
			$headers = [];
			
			// Split the string on every "double" new line.
			$arrRequests = explode("\r\n\r\n", $headerContent);
			
			// Loop of response headers. The "count() -1" is to 
			//avoid an empty row for the extra line break before the body of the response.
			for ($index = 0; $index < count($arrRequests) -1; $index++) {
				foreach (explode("\r\n", $arrRequests[$index]) as $i => $line)
				{
					if ($i === 0)
						$headers[$index]['http_code'] = $line;
					else
					{
						list ($key, $value) = explode(': ', $line);
						$headers[$index][$key] = $value;
					}
				}
			}
			return $headers;
		}
		
		$output['headers'] = extractHeaders($rawHeaders);
		return $output;
	}
	
	// CAT-MH API methods
	public function createInterviews($args) {
		// args needed: instrument, recordID
		$out = [];
		$out['args'] = $args;
		
		// get project/system configuration information
		$interviewConfig = $this->getInterviewConfig($args['instrument']);
		$out['config'] = $interviewConfig;
		
		if ($interviewConfig === false) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "Failed to create interview -- couldn't find interview settings for this instrument: " . $args['instrument'];
			return $out;
		};
		
		// build request headers and body
		$curlArgs = [];
		$curlArgs['headers'] = [
			"applicationid: " . $interviewConfig['applicationid'],
			"Accept: application/json",
			"Content-Type: application/json"
		];
		$curlArgs['body'] = [
			"organizationID" => intval($interviewConfig['organizationid']),
			"userFirstName" => "Automated",
			"userLastName" => "Creation",
			"subjectID" => $interviewConfig['subjectID'],
			"numberOfInterviews" => 1,
			"language" => intval($interviewConfig['language']),
			"tests" => $interviewConfig['tests']
		];
		$curlArgs['post'] = true;
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$curlArgs['address'] = $this->testAPI ? $testAddress : "https://www.cat-mh.com/portal/secure/interview/createInterview";;
		
		$out['curlArgs'] = $curlArgs;
		
		// send request via curl
		$curl = $this->curl($curlArgs);
		$out['curl'] = $curl;
		
		// handle response
		try {
			$json = json_decode($curl['body'], true);
			foreach ($json['interviews'] as $i => $interview) {
				$interview['type'] = $out['config']['tests'][$i]['type'];
				$params = [
					'subjectID' => $interviewConfig['subjectID'],
					'recordID' =>  $args['recordID'],
					'interviewID' => $interview['interviewID'],
					'status' => 0,
					'timestamp' => time(),
					'instrument' => $args['instrument'],
					'identifier' => $interview['identifier'],
					'signature' => $interview['signature'],
					'type' => $interview['type'],
					'label' => $this->testTypes[$interview['type']]
				];
				$this->log("createInterviews", $params);
			}
			$out['success'] = true;
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap couldn't get interview information from CAT-MH API.";
		}
		return $out;
	}
	
	public function authInterview($args) {
		// args needed: subjectID, instrument, recordID, identifier, signature, interviewID
		$args['recordID'] = intval($args['recordID']);
		$args['interviewID'] = intval($args['interviewID']);
		$out = [];
		
		// build request headers and body
		$curlArgs = [];
		$curlArgs['headers'] = [
			"Content-Type: application/x-www-form-urlencoded"
		];
		$curlArgs['body'] = "j_username=" . $args['identifier'] . "&" .
			"j_password=" . $args['signature'] . "&" .
			"interviewID=" . $args['interviewID'];
		$curlArgs['post'] = true;
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$curlArgs['address'] = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/signin";
		$out['curlArgs'] = $curlArgs;
		
		// send request via curl
		$curl = $this->curl($curlArgs);
		$out['curl'] = $curl;
		
		// get JSESSIONID and AWSELB cookies
		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $curl['response'], $matches);
		$cookies = array();
		foreach($matches[1] as $item) {
			parse_str($item, $cookie);
			$cookies = array_merge($cookies, $cookie);
		}
		$out['cookies'] = $cookies;
		
		if (isset($cookies['JSESSIONID']) and isset($cookies['AWSELB'])) {
			$this->removeLogs("subjectID='" . $args['subjectID'] . "' and interviewID=" . $args['interviewID']);
			$args['JSESSIONID'] = $cookies['JSESSIONID'];
			$args['AWSELB'] = $cookies['AWSELB'];
			$args['timestamp'] = time();
			$args['status'] = 1;
			$this->log("authInterview", $args);
			$out['success'] = true;
		} else {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to retrieve authorization details from the CAT-MH API server for the interview.";
		}
		
		return $out;
	}
	
	public function startInterview($args) {
		// args required: JSESSIONID, AWSELB
		$out = [];
		$out['args'] = $args;
		
		$curlArgs = [];
		$curlArgs['headers'] = [
			"Accept: application/json",
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$curlArgs['address'] = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/rest/interview";
		$out['curlArgs'] = $curlArgs;
		
		// send request via curl
		$curl = $this->curl($curlArgs);
		$out['curl'] = $curl;
		
		// handle response
		try {
			$json = json_decode($curl['body'], true);
			if (gettype($json) != 'array') throw new \Exception("json error");
			$out['success'] = true;
			if ($json['id'] > 0) {
				$out['getFirstQuestion'] = true;
			} else {
				$out['terminateInterview'] = true;
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to start the interview via the CAT-MH API.";
		}
		return $out;
	}
	
	public function getQuestion($args) {
		// args required: JSESSIONID, AWSELB
		$out = [];
		$out['args'] = $args;
		
		$curlArgs = [];
		$curlArgs['headers'] = [
			"Accept: application/json",
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$curlArgs['address'] = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/rest/interview/test/question";
		$out['curlArgs'] = $curlArgs;
		
		// send request via curl
		$curl = $this->curl($curlArgs);
		$out['curl'] = $curl;
		
		// handle response
		try {
			$json = json_decode($curl['body'], true);
			if (gettype($json) != 'array') throw new \Exception("json error");
			$out['success'] = true;
			if ($json['questionID'] < 0) {
				$out['getResults'] = true;
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to retrieve the next question from the CAT-MH API server.";
		}
		return $out;
	}
	
	public function submitAnswer($args) {
		// need args: JSESSIONID, AWSELB, questionID, response, duration
		$out = [];
		$out['args'] = $args;
		
		// build request headers and body
		$curlArgs = [];
		$curlArgs['headers'] = [
			"Content-Type: application/json",
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$curlArgs['body'] = [
			"questionID" => $args['questionID'],
			"response" => $args['response'],
			"duration" => $args['duration'],
			"curT1" => 0,
			"curT2" => 0,
			"curT3" => 0
		];
		$curlArgs['post'] = true;
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$curlArgs['address'] = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/rest/interview/test/question";
		$out['curlArgs'] = $curlArgs;
		
		// send request via curl
		$curl = $this->curl($curlArgs);
		$out['curl'] = $curl;
		
		if () {
			
		} else {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap submitted answer but got back a non-OK response from the CAT-MH API server.";
		}
		return $out;
		
		
		
		
		
		
		
		
		
		
		
		// build request headers and body
		$requestHeaders = [
			"Content-Type: application/json",
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$requestBody = [
			"questionID" => $args['questionID'],
			"response" => $args['response'],
			"duration" => $args['duration'],
			"curT1" => 0,
			"curT2" => 0,
			"curT3" => 0
		];
		$out['requestHeaders'] = $requestHeaders;
		$out['requestBody'] = $requestBody;
		
		// curl request
		$ch = curl_init();
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$address = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/rest/interview/test/question";
		curl_setopt($ch, CURLOPT_URL, $address);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out['response'] = curl_exec($ch);
		$out['errorNumber'] = curl_errno($ch);
		$out['error'] = curl_error($ch);
		$out['info'] = curl_getinfo($ch);
		curl_close ($ch);
		
		// handle response
		try {
			if ($out['info']['http_code'] == 200) {
				// request next question
				$out['success'] = true;
			} else {
				throw new \Exception('error');
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap submitted answer but got back a non-OK response from the CAT-MH API server.";
		}
		
		return $out;
	}
	
	public function endInterview($args) {
		// need args: JSESSIONID, AWSELB
		$out = [];
		$out['args'] = $args;
		
		// build request headers and body
		$requestHeaders = [
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$out['requestHeaders'] = $requestHeaders;
		
		// curl request
		$ch = curl_init();
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$address = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/signout";
		curl_setopt($ch, CURLOPT_URL, $address);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out['response'] = curl_exec($ch);
		$out['errorNumber'] = curl_errno($ch);
		$out['error'] = curl_error($ch);
		$out['info'] = curl_getinfo($ch);
		curl_close ($ch);
		
		// handle response
		// // get cookies
		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $out['response'], $matches);
		$cookies = array();
		foreach($matches[1] as $item) {
			parse_str($item, $cookie);
			$cookies = array_merge($cookies, $cookie);
		}
		$out['cookies'] = $cookies;
		
		// get location
		preg_match_all('/^Location:\s([^\n]*)$/m', $out['response'], $matches);
		$out['location'] = trim($matches[1][0]);
		
		try {
			if ($out['info']['http_code'] == 302 and isset($cookies['JSESSIONID'])) {
				// successfully terminated interview
				$out['success'] = true;
			} else {
				throw new \Exception('');
			}
		} catch (\Exception $e) {
			// failure
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to end the interview via the CAT-MH API server.";
		}
		
		return $out;
	}
	
	public function getResults($args) {
		// need args: JSESSIONID, AWSELB
		$out = [];
		$out['args'] = $args;
		
		// build request headers and body
		$requestHeaders = [
			"Accept: application/json",
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$out['requestHeaders'] = $requestHeaders;
		
		// curl request
		$ch = curl_init();
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$address = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/rest/interview/results?itemLevel=1";
		curl_setopt($ch, CURLOPT_URL, $address);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out['response'] = curl_exec($ch);
		$out['errorNumber'] = curl_errno($ch);
		$out['error'] = curl_error($ch);
		$out['info'] = curl_getinfo($ch);
		curl_close ($ch);
		
		// handle response
		$response = json_decode($out['response'], true);
		try {
			if ($response['interviewId'] > 0) {
				$out['success'] = true;
				
				// update interview status in db/logs
				$this->removeLogs("subjectID='" . $args['subjectID'] . "' and interviewID=" . $args['interviewID']);
				$args['timestamp'] = time();
				$args['status'] = 2;
				$this->log("getResults", $args);
				$out['success'] = true;
			} else {
				throw new \Exception ('response malformed');
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to get results for CAT-MH interview.";
		}
		return $out;
	}
	
	public function getInterviewStatus($args) {
		// need args: applicationid, organizationID, interviewID, identifier, signature
		$out = [];
		$out['args'] = $args;
		$config = $this->getInterviewConfig($args['instrument']);
		
		if ($config === false) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "Failed to get interview status -- couldn't find interview settings for this instrument: " . $args['instrument'];
			return $out;
		}
		
		// build request headers and body
		$requestHeaders = [
			"applicationid: " . $config['applicationid'],
			"Accept: application/json",
			"Content-Type: application/json"
		];
		$requestBody = [
			"organizationID" => intval($config['organizationid']),
			"interviewID" => intval($args['interviewID']),
			"identifier" => $args['identifier'],
			"signature" => $args['signature']
		];
		$out['requestBody'] = $requestBody;
		$out['requestHeaders'] = $requestHeaders;
		
		// curl request
		$ch = curl_init();
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$address = $this->testAPI ? $testAddress : "https://www.cat-mh.com/portal/secure/interview/status";
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		curl_setopt($ch, CURLOPT_URL, $address);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
		// curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out['response'] = curl_exec($ch);
		$out['errorNumber'] = curl_errno($ch);
		$out['error'] = curl_error($ch);
		$out['info'] = curl_getinfo($ch);
		curl_close ($ch);
		
		// handle response
		$out['json'] = json_decode($out['response'], true);
		try {
			if (gettype($out['json']) == 'array') {
				$out['success'] = true;
			} else {
				throw new \Exception("error");
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "REDCap failed to get interview status from the CAT-MH API server.";
		}
		
		return $out;
	}
	
	public function breakLock($args) {
		// need args: JSESSONID, AWSELB
		$out = [];
		$out['args'] = $args;
		
		// build request headers and body
		$requestBody = [];
		$requestHeaders = [
			"Cookie: JSESSIONID=" . $args['JSESSIONID'] . "; AWSELB=" . $args['AWSELB']
		];
		$out['requestHeaders'] = $requestHeaders;
		$out['requestBody'] = $requestBody;
		
		// curl request
		$ch = curl_init();
		$testAddress = "http://localhost/redcap/redcap_v8.10.2/ExternalModules/?prefix=cat_mh&page=testEndpoint&pid=" . $this->getProjectId() . "&action=" . __FUNCTION__;
		$address = $this->testAPI ? $testAddress : "https://www.cat-mh.com/interview/secure/breakLock";
		curl_setopt($ch, CURLOPT_URL, $address);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$out['response'] = curl_exec($ch);
		$out['errorNumber'] = curl_errno($ch);
		$out['error'] = curl_error($ch);
		$out['info'] = curl_getinfo($ch);
		curl_close ($ch);
		
		$out['responseHeaders'] = substr($out['response'], 0, $out['info']['header_size']);
		$out['responseBody'] = substr($out['response'], $out['info']['header_size']);
		
		// get location
		preg_match_all('/^Location:\s([^\n]*)$/m', $out['response'], $matches);
		$out['location'] = trim($matches[1][0]);
		
		// handle response
		try {
			if ($out['info']['http_code'] == 302) {
				// follow redirect
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $out['location']);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
				curl_setopt($ch, CURLOPT_VERBOSE, true);
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$out['redirectResponse'] = curl_exec($ch);
				$out['redirectErrorNumber'] = curl_errno($ch);
				$out['redirectError'] = curl_error($ch);
				$out['redirectInfo'] = curl_getinfo($ch);
				curl_close ($ch);
				$out['success'] = true;
				return $out;
			} else {
				throw new \Exception("error");
			}
		} catch (\Exception $e) {
			$out['moduleError'] = true;
			$out['moduleMessage'] = "This interview is locked and REDCap was unable to break the lock via the CAT-MH API.";
		}
		
		return $out;
	}
	
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {
	$catmh = new CAT_MH();
	$json = json_decode(file_get_contents("php://input"), true);
	$action = $json['action'];
	switch ($action) {
		case 'createInterviews':
			$out = $catmh->createInterviews($json['args']);
			echo json_encode($out);
			break;
		case 'authInterview':
			$out = $catmh->authInterview($json['args']);
			echo json_encode($out);
			break;
		case 'startInterview':
			$out = $catmh->startInterview($json['args']);
			echo json_encode($out);
			break;
		case 'getQuestion':
			$out = $catmh->getQuestion($json['args']);
			echo json_encode($out);
			break;
		case 'submitAnswer':
			$out = $catmh->submitAnswer($json['args']);
			echo json_encode($out);
			break;
		case 'endInterview':
			$out = $catmh->endInterview($json['args']);
			echo json_encode($out);
			break;
		case 'getResults':
			$out = $catmh->getResults($json['args']);
			echo json_encode($out);
			break;
		case 'getInterviewStatus':
			$out = $catmh->getInterviewStatus($json['args']);
			echo json_encode($out);
			break;
		case 'breakLock':
			$out = $catmh->breakLock($json['args']);
			echo json_encode($out);
			break;
		case 'getInterviews':
			$out = $catmh->getInterviews($json['args']);
			echo json_encode($out);
			break;
	}
}