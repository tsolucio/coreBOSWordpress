<?php
include_once 'cbwsclib/WSClient.php';

$config = array(
	// Connection parameters
	'url' => 'http://localhost/coacv/',
	'user' => 'admin',
	'password' => '3qzwf8TguFrJ3KrE',
	// Entity mapping to process form fields
	'map' => array(
		// WebServices module name
		'Leads' => array(
			// Field mapping
			'fields' => array(
				'tipodealta',
				'firstname',
				'lastname',
				'siccode',
				'lane',
				'city',
				'code',
				'country',
				'email',
				'mobile',
				'phone',
				'birthday',
				'nlugar',
				'zonarepn',
				'zonarepi',
				'sector',
				'idioma',
				'estudios',
				'nombretitular',
				'apellidostitular',
				'entidad',
				'agencia',
				'dc',
				'cuenta',
			),
		),
	),
);
$config['map']['Leads']['defaults']['company'] = $_REQUEST['firstname'] . ' ' . $_REQUEST['lastname'];
$webform = new WsWebForm($config);
$leadid = $webform->send($_REQUEST);

$wsClient = new WSClient($config['url']);
// Login
if (!$wsClient->doLogin($config['user'], $config['password'])) {
	die('Login error.');
}

//name of the module for which the entry has to be created.
$moduleName = 'Documents';

// get file to upload
$finfo = finfo_open(FILEINFO_MIME); // return mime type ala mimetype extension

foreach ($_FILES as $fldname => $fileinfo) {
	$mtype = finfo_file($finfo, $fileinfo['tmp_name']);
	$model_filename=array(
		'name'=>$fileinfo['name'],
		'size'=>filesize($fileinfo['tmp_name']),
		'type'=>$mtype,
		'content'=>base64_encode(file_get_contents($fileinfo['tmp_name']))
	);

	//fill in the details of the contacts.userId is obtained from loginResult.
	$contactData  = array(
		//'assigned_user_id'=>$userId,
		'notes_title' => $fldname . ': ' .$_REQUEST['firstname'] . ' ' . $_REQUEST['lastname'],
		'filename'=>$model_filename,
		'filetype'=>$model_filename['type'],
		'filesize'=>$model_filename['size'],
		'filelocationtype'=>'I',
		'filedownloadcount'=> 0,
		'filestatus'=>1,
		'folderid' => '22x3',
		'relations' => $leadid,  // besides creating the document it will relate it to this record
	);

	$response = $wsClient->doCreate($moduleName, $contactData);
	$id = $response['id'];
}
