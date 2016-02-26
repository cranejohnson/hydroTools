<?php
/**
 * Description: This script gets iridium messages from the specified mailbox and 
 * parses the data into SHEF format and then drops the data into the shef ingest 
 * folder for AWIPS. Configuration settings are stored in the iGageinfo table on 
 * redrock.
 *
 * This scripts requires php5 IMAP support. This was installed on redrock with 
 * the following command:
 *       'zypper install php5-imap'
 *
 *
 
 *
 * @package get_igage 
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */


chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');
$mysqli->select_db("aprfc");


date_default_timezone_set('UTC');

//Pear log package
require_once 'Log.php';
//Pear cache_lite package
require_once('Cache/Lite.php');


/**
 * Setup PEAR logging utility
 */
#$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
#$logger = Log::singleton('file', LOG_DIRECTORY.basename(__FILE__, '.php').'.log', __FILE__, $conf);
$conf = array('dsn' => "mysqli://$User:$Passwd@$Host/aprfc",
        'identLimit' => 255);
$logger = Log::singleton('sql', 'log_table', __file__, $conf);

$logger->log("Running ".basename(__FILE__, '.php'),PEAR_LOG_DEBUG);


function decode_igage10($email_date,$data,$verbose,$zerostage){

	$sitedata = array();
   	$datalength = strlen($data);
  	if($verbose) echo "Length: $datalength<br>";
	     
	####Unpack and decode the data record time
   	#  Date/Time is formated in a compact 3 byte packet as follows
	# 
	#  Byte 1      7654         month (1..12)
	#                  3210     year % 10 (0..9)
	#  Byte 2      765          3 LS bits of hour
	#                 43210     day (1..31)
	#  Byte 3      765432       minute (0..59)
	#                    10     2 MS bits of hour
	#
	
	$timeb1 = unpack('C',substr($data,0,1));
	$year = ((240 & $timeb1[1]) >> 4) + 2010;
	$month = (15 & $timeb1[1]);
	$timeint = unpack('S',substr($data,1,2));
	$day = ((63488 & $timeint[1]) >>11);
	$temp1 = ((1792 & $timeint[1]) >> 6);
	$temp2 = ((192 & $timeint[1]) >> 6);
	$hour = $temp1 | $temp2;
	$minute = (63 & $timeint[1]);
	$rdate = strtotime("$year/$month/$day $hour:$minute");
	$sitedata['producttime'] = gmdate('Y-m-d H:i',$rdate);

	#Kludge fix to corret when the time gets off
        if(abs($rdate-(strtotime($email_date)))> (3600*1)){
		$sitedata['producttime'] = $email_date;
		echo "Update product time\n";
	}



	####Unpack the number of retries for the last transmission
        $array =  unpack('C',substr($data,3,1));
	$sitedata['tries'] = $array[1];
	                                                      
	####Unpack Logger batt
	$array = unpack('s',substr($data,4,2));
	$sitedata['battery'] = $array[1]/100;
	                                                                      
	####Unpack Internal Temp
	$array = unpack('s',substr($data,6,2));
	$sitedata['paneltemp'] = $array[1]/10;
	            
	####Unpack Raw Depth
	$array = unpack('s',substr($data,8,2));
	$sitedata['distance'] = abs($array[1]/10);
	if($sitedata['distance'] > 384){
		$sitedata['distance'] = -9999;
		$sitedata['calcstage'] = -9999;
	}
	elseif($sitedata['distance'] < 24){
		echo "Bad value\n";
		$sitedata['distance'] = -9999;
		$sitedata['calcstage'] = -9999;
	}
	else{
	###Calculate stage
	 if($zerostage) $sitedata['calcstage']= sprintf("%0.2f",$zerostage - ($sitedata['distance'])/12);
	}
	return $sitedata;	
}

function decode_bigdelta($email_date,$data,$verbose,$zerostage){

	$sitedata = array();
   	$datalength = strlen($data);
  	if($verbose) echo "Length: $datalength<br>";
	     
	####Unpack and decode the data record time
   	#  Date/Time is formated in a compact 3 byte packet as follows
	# 
	#  Byte 1      7654         month (1..12)
	#                  3210     year % 10 (0..9)
	#  Byte 2      765          3 LS bits of hour
	#                 43210     day (1..31)
	#  Byte 3      765432       minute (0..59)
	#                    10     2 MS bits of hour
	#
	
	$timeb1 = unpack('C',substr($data,0,1));
	$year = ((240 & $timeb1[1]) >> 4) + 2010;
	$month = (15 & $timeb1[1]);
	$timeint = unpack('S',substr($data,1,2));
	$day = ((63488 & $timeint[1]) >>11);
	$temp1 = ((1792 & $timeint[1]) >> 6);
	$temp2 = ((192 & $timeint[1]) >> 6);
	$hour = $temp1 | $temp2;
	$minute = (63 & $timeint[1]);
	$rdate = strtotime("$year/$month/$day $hour:$minute");
	$sitedata['producttime'] = gmdate('Y-m-d H:i',$rdate);

	####Unpack the number of retries for the last transmission
        $array =  unpack('C',substr($data,3,1));
	$sitedata['tries'] = ((240 & $array[1]) >> 4);
	                                                      
	####Unpack Logger batt
	$array = unpack('s',substr($data,4,2));
	$sitedata['battery'] = $array[1]/100;
	                                                                      
	####Unpack Internal Temp
	$array = unpack('s',substr($data,6,2));
	$sitedata['paneltemp'] = $array[1]/10;
	            
	####Unpack Corrected Depth
	$array = unpack('s',substr($data,10,2));
	$sitedata['distance'] = abs($array[1]/10);
	if($sitedata['distance'] > 384){
		$sitedata['distance'] = -9999;
		$sitedata['calcstage'] = -9999;
	}
	elseif($sitedata['distance'] < 24){
		echo "Bad value\n";
		$sitedata['distance'] = -9999;
		$sitedata['calcstage'] = -9999;
 	}	
	else{
	 ###Calculate stage
	 if($zerostage) $sitedata['calcstage']= sprintf("%0.2f",$zerostage - ($sitedata['distance'])/12);
	}

	####Unpack Air Temperature
	$array = unpack('s',substr($data,12,2));
	$sitedata['paneltemp'] = $array[1]/10;

	return $sitedata;	
}



function decode_csi($email_date,$data,$verbose,$zerostage){

	# parse 14 byte structure - big endian order
	# bytes     description
	# 1,2       Hours since the start of the current calendar 
	#           year = value
	# 3,4       Battery Voltage = value/10 - 200
	# 5,6       Panel Temperature = value/10 - 200
	# 7,8       Air Temperature = value/10 - 200
	# 9,10      Distance Measurement = value/10 - 200
	# 11,12     Previous Communication Attempts = value/10 - 200

	$sitedata = array();
	$datalength = strlen($data);
	$email_year = gmdate('Y',(strtotime($email_date)));
	$base_time = strtotime("01Jan$email_year 00:00")-24*3600;
	                                  
		                                                
	if($verbose) echo "Length: $datalength<br>";
	             
	$decimal = unpack('n',substr($data,0,2));
	$rec_time = $base_time+$decimal[1]*3600+7*3600; 
	$sitedata['producttime'] = date('Y-m-d H:i',$rec_time);
	             

        ###Unpack Battery Voltage
	$array = unpack('n',substr($data,2,2));
	$sitedata['battery'] = ($array[1]/10)-200;

        ###Unpack Panel Temperature
	$array = unpack('n',substr($data,4,2));
	$sitedata['paneltemp'] = ($array[1]/10)-200;
	
        ###Unpack Air Temperature
	$array = unpack('n',substr($data,6,2));
	$sitedata['airtemp'] = ($array[1]/10)-200;

        ###Unpack Distance
	$array = unpack('n',substr($data,8,2));
	$sitedata['distance'] = ($array[1]/10)-200;
	
        ###Unpack Comm Attempts Voltage
	$array = unpack('n',substr($data,10,2));
	$sitedata['tries'] = ($array[1]/10)-200;

	###Calculate stage
	if($zerostage)$sitedata['calcstage']= sprintf("%0.2f",$zerostage - abs($sitedata['distance'])/12);

	return $sitedata;
	                                                  
	                                                                                                                                                }

function dbinsert($sitedata,$mysqli,$logger){

	$names = '';
	$values = '';


	foreach($sitedata as $name => $value){
		if($name == 'DBTABLE') continue;
		$names .= $name.",";
		$values .= "'".$value."',";
	}
	$names = rtrim($names, ',');	
	$values = rtrim($values,',');
	$insertquery = "INSERT INTO {$sitedata['DBTABLE']} ($names) VALUES ($values)"; 
	$result = $mysqli->query($insertquery);
	if(($mysqli->error )&($mysqli->errno != 1062)){
		$logger->log("dbinsert error:".$mysqli->error,PEAR_LOG_ERR);
        echo "Failed to load db....".$mysqli->error,PEAR_LOG_ERR;
	}	
	return $result;
}

function HG_VB_to_shef($sitedata,$overWrite = true){
        #Kludge to convert snowdepth info to inches
        if($sitedata['pe'] == 'SD') $sitedata['calcstage'] = $sitedata['calcstage']*12;
          
	$shefStr = "";
	if($overWrite) $over = 'R';
	$dc = date('\D\CymdHi',strtotime($sitedata['postingtime']));
	$shefStr .= ".A$over ".$sitedata['lid']." ". date('ymd \Z \D\HHi',strtotime($sitedata['producttime']))."/$dc/";
        $shefStr .= "VBIRZZ ".$sitedata['battery']."/\n";
        $shefStr .= ".A$over ".$sitedata['lid']." ". date('ymd \Z \D\HHi',strtotime($sitedata['producttime']))."/$dc/";
	$shefStr .= $sitedata['pe']."I".$sitedata['ts']."Z ".$sitedata['calcstage']."/\n";

	return $shefStr;
}	
	
function array_to_shef($site,$dataarray,$overWrite = false,$PE){
	$shefStr = "";
	$over = "";
	if($overWrite) $over = 'R';
	foreach($dataarray as $key => $values){
		$dc = date('\D\CymdHi');
		$shefStr .= ".A$over $site ". date('ymd \Z \D\HHi',$key)."/$dc/";
		foreach($values as $shefcode => $val){
			$shefStr .= $shefcode."I".$PE."Z ".trim($val)."/";
		}
		$shefStr .= "\n";	
	}	
	return $shefStr;
}


/**
 *
 * 	MAIN PROGRAM LOGIC
 */ 


$sendshef = 0;
$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";


#################Mailbox Configuration Settings########################
$username = GMAIL_USERNAME;
$password = GMAIL_PASSWORD;

#//Which folders or label do you want to access? - Example: INBOX, All Mail, Trash, labelname 
#//Note: It is case sensitive
$imapmainbox = "iridium_NWS_ingest";
$messagestatus = "ALL";
 
  
//Gmail Connection String
$imapaddress = "{imap.gmail.com:993/imap/ssl}";
   
//Gmail host with folder
$hostname = $imapaddress . $imapmainbox;

$final_box = "sbd_done";

$verbose = false;

$mbox = imap_open($hostname, $username,$password); 

if(!$mbox){
	$logger->log("Could not open iridium inbox ($imapmainbox in account $username) aborting....",PEAR_LOG_ERR);
        exit();
}	
  
#####Spit out the Total Number of Messages from Iridium

$check = imap_check($mbox);

$sbdmes = $check->Nmsgs;

$logger->log("$sbdmes total messages in iridium inbox",PEAR_LOG_DEBUG);

$numnew =  imap_num_recent($mbox);

echo "$numnew New messages from Iridium in inbox ($sbdmes Total Messages)\n";


######Process each message
$emails = imap_search($mbox,'ALL');
if($emails){
	arsort($emails); //JUST DO ARSORT
	foreach($emails as $email_number) {
		$sitedata = array();
		$msgno = $email_number;
		$text = "";
		
		######Get the file name and parse out the datestamp
		$att = imap_bodystruct($mbox,$msgno,2);   
                if($att){                         # no attached file continue to the next email
			$file = $att->dparameters[0]->value;        # Native file format 100808210135.jpg
			$imei = substr($file,0,15);
		}
		$struct = imap_fetchstructure($mbox,$msgno);
		//$contentParts = count($struct->parts);
		$fileContent = imap_fetchbody($mbox,$msgno,2);
		$data = base64_decode($fileContent);
		$string = imap_body($mbox,$msgno);

		preg_match("/UTC\):(.+)\n/",$string,$time);
		$sitedata['postingtime'] = gmdate('Y-m-d H:i', (strtotime($time[1])));
		preg_match("/Lat = (.+) Long/",$string,$array);
		$sitedata['latitude'] = $array[1];
		preg_match("/Long = (.+)\n/",$string,$array);
		$sitedata['longitude'] = $array[1];
		preg_match("/CEPradius = (.+)\n/",$string,$array);
		$sitedata['CEPradius'] = $array[1];
		preg_match("/MOMSN:(.+)\n/",$string,$array);
		$sitedata['momsn'] = $array[1];
                $sitedata['imei'] = $imei;
		if($verbose) echo "IMEI:$imei\n";

		imap_mail_move($mbox,$msgno,$final_box);

                if($data){
			$query = "select lid,datatable,imei,decoder,zerostage,ingest,peCode,typeSource from iGageinfo where imei = $imei and ingest = 1 ";
			$result = $mysqli->query($query);
			if($result->num_rows == 0){
				$logger->log("No site information in database for imei: $imei",PEAR_LOG_NOTICE);
			}
			else{
				$row = $result->fetch_array();
				$sitedata['lid']=$row['lid']; 
                                $sitedata['pe'] = $row['peCode'];
				$sitedata['ts'] = $row['typeSource'];
				$sitedata['DBTABLE']=$row['datatable'];
				$zerostage = $row['zerostage'];

				$decoder = 'decode_'.$row['decoder'];
				if($verbose) echo "Siteid: {$sitedata['lid']}<br>";
				$sbddata = $decoder($sitedata['postingtime'],$data,$verbose,$zerostage);
				$sitedata = array_merge($sitedata,$sbddata);
				dbinsert($sitedata,$mysqli,$logger);
				if($row['ingest']){
					$shefFile .= HG_VB_to_shef($sitedata);
					$sendshef++;
					echo "Send shef: $sendshef\n";
				}
				print_r($sitedata);
			}
		}  #If data loop
		else{
			$logger->log("No data file for imei: $imei",PEAR_LOG_DEBUG);
		}
	}            
}  #Outer if loop


##############Output Shef File#####################################
$fileName = "sheffile.hd.iGage.".date('ymdHi');


/* Write the file to the local temporary location */
file_put_contents(TEMP_DIRECTORY.$fileName, $shefFile);
file_put_contents(TO_LDAD.$fileName, $shefFile);





if($sendshef == 0){
	$logger->log("No sites to ingest to AWIPS, Process Complete!",PEAR_LOG_DEBUG);
 	exit();
}




?>
  