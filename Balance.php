<?php 
ini_set('soap.wsdl_cache_enabled', '0');
//ini_set("default_socket_timeout", 7);
function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

/*
	GUID generating
*/
function getGUID()
{
	$charid = strtoupper(md5(uniqid(rand(), true)));
    $hyphen = chr(45);// "-"
    $uuid = substr($charid, 0, 8).$hyphen
            .substr($charid, 8, 4).$hyphen
            .substr($charid,12, 4).$hyphen
            .substr($charid,16, 4).$hyphen
            .substr($charid,20,12);

    return $uuid;
}


class Balance
{
	public $subscriber;
		
	public function Balance(Subscriber $subscriber, $response, $error){
        $this->subscriber = $subscriber;
        $this->response = $response;
        $this->error = $error;
    }
	
	static function get(Subscriber $subscriber)
	{
		$url = "http://bfvendorc.intra:8080/BillingFacadeVendor/GeocellPortalWS/GeocellBalancePlusWS?wsdl";
		$password = 'pass';
		
		$username = 'balanceplus';
		$response = array();
		$error = 1;
		$LastResponse = '';
		$connection=Yii::app()->db;
		
		$id_soap = 'soap';
		$result_soap = Yii::app()->cache->get($id_soap);
		if($result_soap === false)
		{
			$GetMethods = "select distinct(SoapMethod) from soapservice where SoapMethod != ''";
			$command = $connection->createCommand($GetMethods);
			$result_soap = $command->queryAll();
			Yii::app()->cache->set($id_soap, $result_soap, 300);
		}
		
		$soap = array_map(function($result_soap){return $result_soap['SoapMethod'];}, $result_soap);
	
		$time_start = microtime_float();
		$client = new SoapClient($url, array('trace' => 1, "exceptions" => 1));
		foreach($soap as $SoapMethod)
		{

			$GUID = getGUID(); 

			try
			{
				$request = $client->{$SoapMethod}(array(
													"arg0"	=>	$username,
													"arg1"	=>	md5($username.",".$GUID.",".$subscriber->number.",".$password),
													"arg2"	=>	$GUID,
													"arg3"	=> 	$subscriber->number
													));
			}
			catch (SoapFault $soapFault)
			{
				Yii::log('SoapFault: ' . $soapFault, 'error', 'php');
				return false;
			}
		
			$LastResponse .= $client->__getLastResponse().";";
			$output = $request->return;
			if($output->resultCode == 1)
			{
				$error = 0;
			//	$GetXmlTags = "select XmlTag, VariableName, Type from SoapService WHERE SoapMethod = '".$SoapMethod."'";
				
				$id_tag = 'soap_method';
				$result = Yii::app()->cache->get($id_tag);
			//	$result = false;
				if($result === false)
				{
					$GetXmlTags = "select XmlTag, VariableName, Type from soapservice WHERE SoapMethod = '".$SoapMethod."'";
					$command = $connection->createCommand($GetXmlTags);
					$result = $command->queryAll();
					Yii::app()->cache->set($id_tag, $result, 300);
				}
				
				foreach($result as $value)
				{
					$XmlTag = $value['XmlTag'];
					$VariableName = $value['VariableName'];
					if(substr_count($XmlTag, '.') > 0)
					{
						$XmlPath = explode('.', $XmlTag);
						if(count($output->{$XmlPath[0]}) == 1)
						{
							$response[$VariableName][0] = $output->{$XmlPath[0]}->{$XmlPath[1]};
						}
						else
						{
							for($i = 0; $i < count($output->{$XmlPath[0]}); $i++)
							{
								if(isset($output->{$XmlPath[0]}[$i]->{$XmlPath[1]}))
								{
									$response[$VariableName][$i] = $output->{$XmlPath[0]}[$i]->{$XmlPath[1]};
									if(substr_count($response[$VariableName][$i],'/') > 1)
									{
										$datetime = DateTime::createFromFormat('d/m/Y H:i:s', $response[$VariableName][$i]);
										$response[$VariableName][$i] = $datetime->format('Y-m-d H:i:s');
									}
								}
								else
								{
									$response[$VariableName][$i] = NULL;
								}
							}
						}
					}
					else
					{
						if(isset($output->{$XmlTag}))
						{
							$response[$VariableName] = $output->{$XmlTag};
							if(substr_count($response[$VariableName],'/') > 1)
							{
								$datetime = DateTime::createFromFormat('d/m/Y H:i:s', $response[$VariableName]);
								$response[$VariableName] = $datetime->format('Y-m-d H:i:s');
							}
						}
						else
						{
							$response[$VariableName] = NULL;
							Yii::log('xml tag <' . $XmlTag . "> doesn't exist", 'notice', 'php');
						}
					}
				}
			}
			else
			{
				$error = 1;
				Yii::log('subscriber: '.$subscriber->number.'; soapmethod: '.$SoapMethod.'; resultCode: ' . $output->resultCode . "; resultMsg: ".$output->resultMsg, 'error', 'php');
				$sql = "INSERT INTO `ErrorLogs`(`subscriber`,`SoapMethod`,`resultCode`,`resultMsg`) VALUES (:msisdn, :method, :code, :msg)";
				$command = $connection->createCommand($sql);
				$results = $command->execute(array(':msisdn' => $subscriber->number, ':method' => $SoapMethod, ':code' => $output->resultCode, ':msg' => $output->resultMsg));
			}
		}

		if($response['$accountBlockingStatus'] == 104)
		{
		//	$cmd = "/usr/bin/php /www/ussd/SimActivation.php \"".$subscriber->number."\"";
		//	exec($cmd . " > /dev/null &");
			$LastResponse = '';
			syslog(LOG_INFO, 'NEW BF TEST: subscriber => '.$subscriber->number.'; accountBlockingStatus => '.$response['$accountBlockingStatus']);
			$time_start_activation = microtime_float();
			$GUID = getGUID(); 
			$request_activation = $client->balanaceActivateSubscriberSIM(array("arg0"	=>	$username,
										"arg1"	=>	md5($username.",".$GUID.",".$subscriber->number.",".$password),
										"arg2"	=>	$GUID,
										"arg3"	=> 	$subscriber->number
									));

			$time_end_activation = microtime_float();
			$time_activation = $time_end_activation - $time_start_activation;

			syslog(LOG_INFO, 'B+ NEW SUBSCRIBER ACTIVATION: subscriber => '.$subscriber->number.'; response => '.$client->__getLastResponse().'; response time => '.$time_activation);
			
			$output_activation = $request_activation->return;
			if($output_activation->resultCode == '1')
			{
				foreach($soap as $SoapMethod)
				{
					$GUID = getGUID(); 

					try
					{
						$request = $client->{$SoapMethod}(array(
															"arg0"	=>	$username,
															"arg1"	=>	md5($username.",".$GUID.",".$subscriber->number.",".$password),
															"arg2"	=>	$GUID,
															"arg3"	=> 	$subscriber->number
															));
					}
					catch (SoapFault $soapFault)
					{
						Yii::log('SoapFault: ' . $soapFault, 'error', 'php');
						return false;
					}
				
					$LastResponse .= $client->__getLastResponse().";";
					$output = $request->return;
					if($output->resultCode == 1)
					{
						$error = 0;
	
						$GetXmlTags = "select XmlTag, VariableName, Type from soapservice WHERE SoapMethod = '".$SoapMethod."'";
						$command = $connection->createCommand($GetXmlTags);
						$result = $command->queryAll();
						
						foreach($result as $value)
						{
							$XmlTag = $value['XmlTag'];
							$VariableName = $value['VariableName'];
							if(substr_count($XmlTag, '.') > 0)
							{
								$XmlPath = explode('.', $XmlTag);
								if(count($output->{$XmlPath[0]}) == 1)
								{
									$response[$VariableName][0] = $output->{$XmlPath[0]}->{$XmlPath[1]};
								}
								else
								{
									for($i = 0; $i < count($output->{$XmlPath[0]}); $i++)
									{
										if(isset($output->{$XmlPath[0]}[$i]->{$XmlPath[1]}))
										{
											$response[$VariableName][$i] = $output->{$XmlPath[0]}[$i]->{$XmlPath[1]};
											if(substr_count($response[$VariableName][$i],'/') > 1)
											{
												$datetime = DateTime::createFromFormat('d/m/Y H:i:s', $response[$VariableName][$i]);
												$response[$VariableName][$i] = $datetime->format('Y-m-d H:i:s');
											}
										}
										else
										{
											$response[$VariableName][$i] = NULL;
										}
									}
								}
							}
							else
							{
								if(isset($output->{$XmlTag}))
								{
									$response[$VariableName] = $output->{$XmlTag};
									if(substr_count($response[$VariableName],'/') > 1)
									{
										$datetime = DateTime::createFromFormat('d/m/Y H:i:s', $response[$VariableName]);
										$response[$VariableName] = $datetime->format('Y-m-d H:i:s');
									}
								}
								else
								{
									$response[$VariableName] = NULL;
									Yii::log('xml tag <' . $XmlTag . "> doesn't exist", 'notice', 'php');
								}
							}
						}
					}
					else
					{
						$error = 1;
						Yii::log('subscriber: '.$subscriber->number.'; soapmethod: '.$SoapMethod.'; resultCode: ' . $output->resultCode . "; resultMsg: ".$output->resultMsg, 'error', 'php');
						$sql = "INSERT INTO `ErrorLogs`(`subscriber`,`SoapMethod`,`resultCode`,`resultMsg`) VALUES (:msisdn, :method, :code, :msg)";
						$command = $connection->createCommand($sql);
						$results = $command->execute(array(':msisdn' => $subscriber->number, ':method' => $SoapMethod, ':code' => $output->resultCode, ':msg' => $output->resultMsg));
					}
				}
			}
			else
			{
				$error = 1;
				Yii::log('subscriber: '.$subscriber->number.'; soapmethod: balanaceActivateSubscriberSIM; resultCode: ' . $output_activation->resultCode . "; resultMsg: ".$output_activation->resultMsg, 'error', 'php');
				$sql = "INSERT INTO `ErrorLogs`(`subscriber`,`SoapMethod`,`resultCode`,`resultMsg`) VALUES (:msisdn, :method, :code, :msg)";
				$command = $connection->createCommand($sql);
				$results = $command->execute(array(':msisdn' => $subscriber->number, ':method' => 'balanaceActivateSubscriberSIM', ':code' => $output_activation->resultCode, ':msg' => $output_activation->resultMsg));
			}
		}
	
		
		$fp = fopen("/var/log/xml/response.log", 'a');
		if($subscriber->number == '995593164680')
		{
			fwrite($fp, "[".date("Y-m-d H:i:s")."]: ".$subscriber->number." - ".$url." - ".$LastResponse.PHP_EOL);
		}
		else
		{
			fwrite($fp, "[".date("Y-m-d H:i:s")."]: ".$subscriber->number." - ".$LastResponse.PHP_EOL);
		}
		fclose($fp);
		
		$time_end = microtime_float();
		$time = $time_end - $time_start;
		Yii::log('request: ' . $subscriber->number . " - " . $time, 'profile', 'request');
		
		$balance = new Balance($subscriber, $response, $error);
        return $balance;
	}
}
