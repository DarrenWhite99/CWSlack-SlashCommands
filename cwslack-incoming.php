<?php
/* 	
	CWSlack-SlashCommands
    Copyright (C) 2018  jundis

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>. 
*/

//Receive connector for Connectwise Callbacks
ini_set('display_errors', 1); //Display errors in case something occurs
header('Content-Type: application/json'); //Set the header to return JSON, required by Slack
require_once 'config.php'; //Require the config file.
require_once 'functions.php';

$data = json_decode(file_get_contents('php://input')); //Decode incoming body from connectwise callback.
if($data==NULL)
{
	die("No ticket data was submitted. This is expected behavior if you are just browsing to this page with a web browser.");
}
$info = json_decode(stripslashes($data->Entity)); //Decode the entity field which contains the JSON data we want.

//Connection kill blocks. Stops things from running if certain conditions are met.
if(empty($_REQUEST['id']) || empty($_REQUEST['action']) || empty($info)) die; //If anything we need doesn't exist, kill connection.

if($_REQUEST['action'] == "updated" && $_REQUEST['srDetailRecId']==0 && $_REQUEST['timeRecId']==0) die; //Kill connection if the update is not a note, and is something like a status change. This will prevent duplicate entries.

if($_REQUEST['isProblemDescription']=="False" && $_REQUEST['isInternalAnalysis']=="False" && $_REQUEST['isResolution']=="False") die; //Die if no actual update.

error_log("Evaluating Request: " . print_r($_REQUEST,true));

$badboards = explode("|",$badboard); //Explode with pipe seperator.
$badstatuses = explode("|",$badstatus); //Explode with pipe seperator.
$badcompanies = explode("|",$badcompany); //Explode with pipe seperator.
$priorities = explode("|",$prioritylist);
$prioritystatuses = explode("|",$prioritystatus);
if (in_array($info->BoardName,$badboards)) die;
if (in_array($info->StatusName,$badstatuses)) die;
if (in_array($info->CompanyName,$badcompanies)) die;

$channel = NULL; //Set channel to NULL for future use.
$priorityset = NULL;
$prioritystatusset = NULL;

if (!empty($boardmapping))
{
	$explode = explode(",",$boardmapping);
	foreach($explode as $item) {
		$temp = explode("|",$item);
		$boardtest1=$temp[0];
		error_log(print_r("comparing with board before testing for boardmapping",true));
		error_log(print_r($boardtest1,true));
		
				
		if(strcasecmp($temp[0],$info->BoardName) == 0) {
			$channel = $temp[1];
		}
	}
}
else if (!empty($_REQUEST['board']))
{
	if(strpos($_REQUEST['board'], "-") !== false)
	{
		$tempboards = explode("-", $_REQUEST['board']);
		if(!in_array($info->BoardName, $tempboards))
		{
			die("Incorrect board");
		}
	}
	else if($_REQUEST['board'] != $info->BoardName)
	{
		die("Incorrect board");
	}

	if(!empty($_REQUEST['channel']))  //If using channels in URL is set, and channel is not empty..
	{
		$channel = $_REQUEST['channel']; //Set $channel to the channel.
		error_log(print_r("not in boardmappings, if default",true));
		error_log(print_r($channel,true));
	}
}

if (!empty($priorities)) 
{
	$explode = explode("|",$prioritylist);
	foreach($explode as $item) {
		if(strcasecmp($item,$info->Priority) == 0) {
			$priorityset = 1;
			
		}
	}
		
}

if (!empty($prioritystatuses)) 
{
	$explode = explode("|",$prioritystatus);
	foreach($explode as $item) {
		if(strcasecmp($item,$info->TicketStatus) == 0) {
			$prioritystatusset = 1;
			
		}
	}
		
}
		

error_log("Pre-tests passed for received data. Parsed Result: " . print_r($info,true));

//error_log(print_r($channel,true));

//URL creation
$ticketurl = $connectwise . "/$connectwisebranch/services/system_io/Service/fv_sr100_request.rails?service_recid="; //Set the URL required for ticket links.
$noteurl = $connectwise . "/$connectwisebranch/apis/3.0/service/tickets/" . $_REQUEST['id'] . "/notes?orderBy=id%20desc"; //Set the URL required for cURL requests to ticket note API.
$timeurl = $connectwise . "/$connectwisebranch/apis/3.0/time/entries?conditions=chargeToId=" . $_REQUEST['id'] . "&chargeToType=%27ServiceTicket%27&orderBy=_info/dateEntered%20desc"; //Set the URL required for cURL requests to the time entry API.

$dataTData = array(); //Blank array.
$dataTimeData = array(); //Blank array.
$dateformat = "None"; //Just in case!
$noteisinternal = false; //Default note type

//Set headers for cURL requests. $header_data covers API authentication while $header_data2 covers the Slack output.
$header_data = authHeader($companyname, $apipublickey, $apiprivatekey); // Authorization array. Auto encodes API key for auhtorization.
$header_data2 =array(
 "Content-Type: application/json"
);

$skip = 0; //Create variable to skip posting to Slack channel while also allowing follow posts.
$date=strtotime($info->EnteredDateUTC . " GMT"); //Convert date entered JSON result to time.
$dateformat="<!date^" . $date . "^{date_num} {time_secs}|". date('m-d-Y g:i:sa',$date) . " UTC>"; //Convert previously converted time to a better Slack time string.

if($_REQUEST['action'] == "updated")
{
//	$createddate = date_create();
//	date_timestamp_set($createddate, strtotime($info->EnteredDateUTC));
//	date_add($createddate, new DateInterval('PT2S')); //Set creation timestamp forward by 2 seconds for comparison with updateddate

//	$updateddate = date_create();
//	date_timestamp_set($updateddate, strtotime($info->LastUpdatedUTC));

//	if($createddate > $updateddate) die; //Kill connection if the update occured within 2 seconds of creation.

	$createddate = strtotime($info->EnteredDateUTC . " GMT") + 2; //Set creation timestamp forward by 2 seconds for comparison with updateddate
	$updateddate = strtotime($info->LastUpdatedUTC . " GMT");
	if($createddate > $updateddate) die; //Kill connection if the update occured within 2 seconds of creation.
}

$ticket=$_REQUEST['id'];
$usetime = 0; //For posttext internal vs external flag.
$dataarray = NULL; //For internal vs external flag.
$text = "Error";

if($posttext==1) //Block for curl to get latest note
{
	$createdby = "Error"; //Create with error just in case.
	$notetext = "Error"; //Create with error just in case.
	$notetime = 0;

	$dataTData = cURL($noteurl, $header_data); //Decode the JSON returned by the CW API.

	$dataTimeData = cURL($timeurl, $header_data); //Decode the JSON returned by the CW Time Entry API.

	if(is_array($dataTData) && array_key_exists(0, $dataTData) && $dataTData[0]->text != NULL)
	{
		$createdby = $dataTData[0]->createdBy; //Set $createdby to the ticket note creator.
		$text = $dataTData[0]->text; //Set $text to the ticket text.
//		$notetime = new DateTime($dataTData[0]->dateCreated); //Create new datetime object based on ticketnote note.
		$notetime = new DateTime($dataTData[0]->_info->lastUpdated); //Create new datetime object based on ticketnote note.
	}

	if (is_array($dataTimeData) && array_key_exists(0, $dataTimeData) && $dataTimeData[0]->notes != NULL) //Check if arrays exist properly.
	{
//		$timetime = new DateTime($dataTimeData[0]->dateEntered); //Create new time object based on time entry note.
		$timetime = new DateTime($dataTimeData[0]->_info->lastUpdated); //Create new time object based on time entry note.

		if ($timetime > $notetime) //If the time entry is newer than latest ticket note.
		{
			$createdby = $dataTimeData[0]->_info->enteredBy; //Set $createdby to the time entry creator.
			$text = $dataTimeData[0]->notes; //
			$usetime = 1; //Set time flag.
		}
	}

	if($text == "Error")
	{
		error_log("Disabling Text Post, Ticket or Time Data was not valid. Info Returned: " . print_r($dataTData,true));
		$posttext = 0;
	}

	if ($usetime == 1)
	{
		$dataarray = $dataTimeData[0];
		if($dataarray->addToInternalAnalysisFlag == true && $dataarray->addToResolutionFlag != true && $dataarray->addToDetailDescriptionFlag != true) {$noteisinternal = true;}
		$notedate = strtotime($dataarray->_info->lastUpdated);
//		$dateformat2=date('m-d-Y g:i:sa',strtotime($notedate));
		$dateformat2="<!date^" . $notedate . "^{date_num} {time_secs}|". date('m-d-Y g:i:sa',$notedate) . " >"; //Convert time to a better Slack time string.
	}
	else
	{
		$dataarray = $dataTData[0];
		$noteisinternal = ($dataarray->internalAnalysisFlag == "true");
		$notedate = strtotime($dataarray->_info->lastUpdated);
//		$notedate = strtotime($dataarray->dateCreated);
//		$dateformat2=date('m-d-Y g:i:sa',strtotime($notedate));
		$dateformat2="<!date^" . $notedate . "^{date_num} {time_secs}|". date('m-d-Y g:i:sa',$notedate) . " >"; //Convert time to a better Slack time string.
	}
	if(abs($notedate-$updateddate) > 10 ) //Ticket update was more than 10 seconds after latest note/time entry update. Ignore.
	{
		error_log("Ignoring ticket update. Latest note/time entry is more than 10 seconds old.");
		die;
	}
}
//error_log(print_r("just before posting, value of channel",true));
//error_log(print_r($channel,true));

if($_REQUEST['action'] == "added" && $postadded == 1)
{

	if($posttext==0)
	{
		$postfieldspre = array(
			"channel" => ($channel!=NULL ? "#" . $channel : NULL),
			"parse" => "full", 
			"text" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
			"mrkdwn" => false,
			"blocks" => array(
				array(
					"type" => "context",
					"elements" => array(
						array("type" => "plain_text","text" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".")
					)
				),
				array(
					"type" => "section",
					"text" => array("type" => "mrkdwn","text" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: *". $info->Summary ."*"),
					"fields" => array(
						array("type" => "plain_text","text" => "Company: " . $info->CompanyName), //Return Company
						array("type" => "plain_text","text" => "Contact: " . $info->ContactName), //Return Contact
						array("type" => "plain_text","text" => "Priority: " . $info->Priority), //Return Priority
						array("type" => "plain_text","text" => "Status: " . $info->StatusName), //Return Status
						array("type" => "mrkdwn","text" => "Created: " . $dateformat), //Return Date Entered
						array("type" => "plain_text","text" => "Resources Remaining: " . ($info->Resources != "" ? $info->Resources : "None")) //Return assigned resources
					)
				)
			)
		);
	}
	else
	{
		$postfieldspre = array(
			"channel" => ($channel!=NULL ? "#" . $channel : NULL),
			"parse" => "full", 
			"text" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
			"mrkdwn" => false,
			"blocks" => array(
				array(
					"type" => "context",
					"elements" => array(
						array("type" => "plain_text","text" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".")
					)
				),
				array(
					"type" => "section",
					"text" => array("type" => "mrkdwn","text" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: *". $info->Summary ."*"),
					"fields" => array(
						array("type" => "plain_text","text" => "Company: " . $info->CompanyName), //Return Company
						array("type" => "plain_text","text" => "Contact: " . $info->ContactName), //Return Contact
						array("type" => "plain_text","text" => "Priority: " . $info->Priority), //Return Priority
						array("type" => "plain_text","text" => "Status: " . $info->StatusName), //Return Status
						array("type" => "mrkdwn","text" => "Created: " . $dateformat), //Return Date Entered
						array("type" => "plain_text","text" => "Resources Remaining: " . ($info->Resources != "" ? $info->Resources : "None")) //Return assigned resources
					)
				)
			),
			"attachments"=>array(
				array(
					"blocks" => array(
						array(
							"type" => "section",
							"text" => array(
								"type" => "mrkdwn",
								"text" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby
							)
						),
						array(
							"type" => "section",
							"text" => array(
								"type" => "plain_text",
								"text" => $text
							)
						),
						array("type" => "divider")
					)
				)
			)
		);
	}
}
else if($_REQUEST['action'] == "updated" && $postupdated == 1)
{
	if($posttext==0)
	{
		$postfieldspre = array(
			"channel" => ($channel!=NULL ? "#" . $channel : NULL),
			"parse" => "full", 
			"text" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
			"mrkdwn" => false,
			"blocks" => array(
				array(
					"type" => "context",
					"elements" => array(
						array("type" => "plain_text","text" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . ".")
					)
				),
				array(
					"type" => "section",
					"text" => array("type" => "mrkdwn","text" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: *". $info->Summary ."*"),
					"fields" => array(
						array("type" => "plain_text","text" => "Company: " . $info->CompanyName), //Return Company
						array("type" => "plain_text","text" => "Contact: " . $info->ContactName), //Return Contact
						array("type" => "mrkdwn","text" => "Created: " . $dateformat), //Return Date Entered
						array("type" => "plain_text","text" => "Status: " . $info->StatusName), //Return Status
						array("type" => "plain_text","text" => "Resources Remaining: " . ($info->Resources != "" ? $info->Resources : "None")) //Return assigned resources
					)
				)
			)
		);
	}
	else
	{
		$postfieldspre = array(
			"channel" => ($channel!=NULL ? "#" . $channel : NULL),
			"parse" => "full", 
			"text" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
			"mrkdwn" => false,
			"blocks" => array(
				array(
					"type" => "context",
					"elements" => array(array("type" => "plain_text","text" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . "."))
				),
				array(
					"type" => "section",
					"text" => array("type" => "mrkdwn","text" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: *". $info->Summary ."*"),
					"fields" => array(
						array("type" => "plain_text","text" => "Company: " . $info->CompanyName), //Return Company
						array("type" => "plain_text","text" => "Contact: " . $info->ContactName), //Return Contact
						array("type" => "mrkdwn","text" => "Created: " . $dateformat), //Return Date Entered
						array("type" => "plain_text","text" => "Status: " . $info->StatusName), //Return Status
						array("type" => "plain_text","text" => "Resources Remaining: " . ($info->Resources != "" ? $info->Resources : "None")) //Return assigned resources
					)
				)
			),
			"attachments"=>array(
				array(
					"blocks" => array(
						array(
							"type" => "section",
							"text" => array(
								"type" => "mrkdwn",
								"text" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby
							)
						),
						array(
							"type" => "section",
							"text" => array(
								"type" => "plain_text",
								"text" => $text
							)
						),
						array("type" => "divider")
					)
				)
			)
		);
	}
}
else
{
	$skip=1;
}

if($skip==0)
{
	if(strcasecmp($channel,"ms-cw-alerts") == 0)
	{
		cURLPost($webhookurlalerts, $header_data2, "POST", $postfieldspre);
		error_log(print_r("Array sent to slack channel",true));
		error_log(print_r($postfieldspre,true));
		error_log(print_r($channel,true));
	}
	else
	{
		cURLPost($webhookurl, $header_data2, "POST", $postfieldspre);
		error_log(print_r("Array sent to slack channel",true));
		error_log(print_r($postfieldspre,true));
		error_log(print_r($channel,true));
	}
}

if($priorityset == 1)
	{
		cURLPost($webhookurlpriority, $header_data2, "POST", $postfieldspre);
		error_log(print_r("Array sent to slack",true));
		error_log(print_r($postfieldspre,true));
		error_log(print_r($channel,true));
		
	}
	
if($prioritystatusset == 1)
	{
		cURLPost($webhookurlschedule, $header_data2, "POST", $postfieldspre);
		error_log(print_r("Array sent to slack",true));
		error_log(print_r($postfieldspre,true));
		error_log(print_r($channel,true));
		
	}

if($followenabled==1)
{
	$alerts = array(); //Create a blank array.

	if($usedatabase==1)
	{
		$mysql = mysqli_connect($dbhost, $dbusername, $dbpassword, $dbdatabase); //Connect MySQL
		if (!$mysql) //Check for errors
		{
			die("Connection Error: " . mysqli_connect_error()); //Die with error if error found
		}

		$val1 = mysqli_real_escape_string($mysql,$ticket);
		$sql = "SELECT * FROM `follow` WHERE `ticketnumber`=\"" . $val1 . "\""; //SQL Query to select all ticket number entries

		$result = mysqli_query($mysql, $sql); //Run result

		if(mysqli_num_rows($result) > 0) //If there were rows matching query
		{
			while($row = mysqli_fetch_assoc($result)) //While we still have rows to work with
			{
				$alerts[]=$row["slackuser"]; //Add user to alerts array.
			}
		}
	}
	else
	{
		die();
	}

	if(!empty($alerts)) {
		foreach ($alerts as $username) //For each user in alerts array, set $postfieldspre to the follow message.
		{
			if ($_REQUEST['action'] == "added")
			{
				if ($posttext == 0)
				{
					$postfieldspre = array(
						"channel" => "@" . $username,
						"attachments" => array(array(
							"fallback" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
							"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
							"pretext" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".",
							"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
								"\n" . "Priority: " . $info->Priority . " | " . $info->StatusName . //Return "Prority / Status" string
								"\n" . $info->Resources, //Return assigned resources
							"mrkdwn_in" => array(
								"text",
								"pretext",
								"title"
							)
						))
					);
				} else {
					$postfieldspre = array(
						"channel" => "@" . $username,
						"attachments" => array(array(
							"fallback" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
							"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
							"pretext" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".",
							"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
								"\n" . "Priority: " . $info->Priority . " | " . $info->StatusName . //Return "Prority / Status" string
								"\n" . $info->Resources, //Return assigned resources
							"mrkdwn_in" => array(
								"text",
								"pretext",
								"title"
							)
						),
							array(
								"pretext" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby,
								"text" => $text,
								"mrkdwn_in" => array(
									"text",
									"pretext",
									"title"
								)
							))
					);
				}
			} else if ($_REQUEST['action'] == "updated") {
				if ($posttext == 0) {
					$postfieldspre = array(
						"channel" => "@" . $username,
						"attachments" => array(array(
							"fallback" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
							"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
							"pretext" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . ".",
							"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
								"\n" . $dateformat . " | " . $info->StatusName . //Return "Date Entered / Status" string
								"\n" . $info->Resources, //Return assigned resources
							"mrkdwn_in" => array(
								"text",
								"pretext"
							)
						))
					);
				} else {
					$postfieldspre = array(
						"channel" => "@" . $username,
						"attachments" => array(array(
							"fallback" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
							"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
							"pretext" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . ".",
							"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
								"\n" . $dateformat . " | " . $info->StatusName . //Return "Date Entered / Status" string
								"\n" . $info->Resources, //Return assigned resources
							"mrkdwn_in" => array(
								"text",
								"pretext"
							)
						),
							array(
								"pretext" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby,
								"text" => $text,
								"mrkdwn_in" => array(
									"text",
									"pretext",
									"title"
								)
							)
						)
					);
				}
			}
			
			cURLPost($webhookurl, $header_data2, "POST", $postfieldspre);
			error_log(print_r("Array sent to slack User",true));
			error_log(print_r($postfieldspre,true));
			error_log(print_r($Username,true));
		}
	}
}

//Block for if ticket time reaches past X value
if($timeenabled==1 && $info->ActualHours>$timepast)
{
	if($_REQUEST['action'] == "added")
	{
		if($posttext==0)
		{
			$postfieldspre = array(
				"channel"=>$timechan,
				"attachments"=>array(array(
					"fallback" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
					"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: ". $info->Summary,
					"color" => "#F0E68C",
					"pretext" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".",
					"text" =>  $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
						"\n" . "Priority: " . $info->Priority . " | " . $info->StatusName . //Return "Prority / Status" string
						"\n" . $info->Resources . " | Total Hours: *" . $info->ActualHours . "*", //Return assigned resources
					"mrkdwn_in" => array(
						"text",
						"pretext",
						"title"
					)
				))
			);
		}
		else
		{
			$postfieldspre = array(
				"channel"=>$timechan,
				"attachments"=>array(array(
					"fallback" => (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) ." created #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
					"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: ". $info->Summary,
					"color" => "#F0E68C",
					"pretext" => "Ticket #" . $ticket . " has been created by " . (strtolower($_REQUEST['memberId'])=="zadmin" ? $info->ContactName : $info->UpdatedBy) . ".",
					"text" =>  $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
						"\n" . "Priority: " . $info->Priority . " | " . $info->StatusName . //Return "Prority / Status" string
						"\n" . $info->Resources . " | Total Hours: *" . $info->ActualHours . "*", //Return assigned resources
					"mrkdwn_in" => array(
						"text",
						"pretext",
						"title"
					)
				),
					array(
						"pretext" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby,
						"text" =>  $text,
						"mrkdwn_in" => array(
							"text",
							"pretext",
							"title"
						)
					))
			);
		}
	}
	else if($_REQUEST['action'] == "updated")
	{
		if ($posttext == 0) {
			$postfieldspre = array(
				"channel" => $timechan,
				"attachments" => array(array(
					"fallback" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
					"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
					"color" => "#F0E68C",
					"pretext" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . ".",
					"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
						"\n" . $dateformat . " | " . $info->StatusName . //Return "Date Entered / Status" string
						"\n" . $info->Resources . " | Total Hours: *" . $info->ActualHours . "*", //Return assigned resources
					"mrkdwn_in" => array(
						"text",
						"pretext"
					)
				))
			);
		} else {
			$postfieldspre = array(
				"channel" => $timechan,
				"attachments" => array(array(
					"fallback" => $info->UpdatedBy . " updated #" . $ticket . " - " . ($postcompany ? "(" . $info->CompanyName . ") " : "") . $info->Summary,
					"title" => "<" . $ticketurl . $ticket . "&companyName=" . $companyname . "|#" . $ticket . ">: " . $info->Summary,
					"color" => "#F0E68C",
					"pretext" => "Ticket #" . $ticket . " has been updated by " . $info->UpdatedBy . ".",
					"text" => $info->CompanyName . " | " . $info->ContactName . //Return "Company / Contact" string
						"\n" . $dateformat . " | " . $info->StatusName . //Return "Date Entered / Status" string
						"\n" . $info->Resources . " | Total Hours: *" . $info->ActualHours . "*", //Return assigned resources
					"mrkdwn_in" => array(
						"text",
						"pretext"
					)
				),
					array(
						"pretext" => "Latest " . ($noteisinternal ? "Internal" : "External") . " Note (" . $dateformat2 . ") from: " . $createdby,
						"text" => $text,
						"mrkdwn_in" => array(
							"text",
							"pretext",
							"title"
						)
					)
				)
			);
		}
	}

	cURLPost($webhookurl, $header_data2, "POST", $postfieldspre);
}


?>
