<?php
/**
 * Basic class to get the page count of a list of files
 *
 * Version:     1.0
 * By:          Sander Schat
 * Date:        24 June 2017
 * 
 * Modify By:	Giuseppe Maccario
 * Date:        18 July 2017
 */
new get_data();

class get_data {
	
	private $order_cleaning_treshold = "-1 month"; /* TODO ... change in month*/
	private $order_startdate_cleaning_treshold = '1970-01-01';
	private $q_check_old_orders = "SELECT ID FROM %s WHERE post_type = 'shop_order' AND post_status IN ('%s') AND post_date BETWEEN '%s 00:00:00' AND '%s 23:59:59' %s ORDER BY ID DESC";
	
	private $tmp_root_path = 	'/gravity_forms/4-b312dbb997dbdda943c5331828c6e118/';
	private $tmp_path = 		'/gravity_forms/4-b312dbb997dbdda943c5331828c6e118/tmp/';
	
	private $option_orderid_x_session = 	'_woo_custom_order_id_x_session|o:%s';
	private $option_numberofpages_x_file = 	'_woo_custom_option_number_of_pages_x_file|t:%s';
	private $option_higher_id_delete_order ='_woo_custom_order_higher_id_delete_order';
	
	/**
	 * create the ajax calls for the frontend
	 *
	 * get_data constructor
	 */
	public function __construct() 
	{
		// default AJAX call to get list
		add_action( 'wp_ajax_get_pages', array( $this, 'getPages' ) );
		add_action( 'wp_ajax_nopriv_get_pages', array( $this, 'getPages' ) );
		
		add_action( 'wp_ajax_getOptionsNumberPagesToFile', array( $this, 'getOptionsNumberPagesToFile' ) );
		add_action( 'wp_ajax_nopriv_getOptionsNumberPagesToFile', array( $this, 'getOptionsNumberPagesToFile' ) );
		
		add_action( 'wp_ajax_checkOldOrders', array( $this, 'checkOldOrders' ) );
		add_action( 'wp_ajax_nopriv_checkOldOrders', array( $this, 'checkOldOrders' ) );
		
		add_action( 'wp_ajax_deleteOldOrders', array( $this, 'deleteOldOrders' ) );
		add_action( 'wp_ajax_nopriv_deleteOldOrders', array( $this, 'deleteOldOrders' ) );

		add_action( 'wp_ajax_downloadzipfile', array( $this, 'downloadzipfile' ) );
		add_action( 'wp_ajax_nopriv_downloadzipfile', array( $this, 'downloadzipfile' ) );

	}

	public function get_tmp_path()
	{
		return $this->tmp_path;
	}
	public function get_option_orderid_x_session()
	{
		return $this->option_orderid_x_session;
	}
	public function get_option_numberofpages_x_file()
	{
		return $this->option_numberofpages_x_file;
	}
	
	function getCustomtoken()
	{
		$token = wp_get_session_token();
	
		if( !empty( $token ))
		{
			return $token;
		}
		else {
			return $_COOKIE[ 'woo_custom_cookie' ];
		}
	}
	
	/**
	 * return the option _option_number_of_pages_x_file
	 *
	 *
	 */
	public function getOptionsNumberPagesToFile()
	{
		$session_order = str_replace('"', '', json_encode( get_option( sprintf( $this->option_orderid_x_session, $_POST['data']['postID'] ) )));

		wp_send_json( get_option( sprintf( $this->option_numberofpages_x_file, $session_order ) ) );
		
		die();
	}
	
	/**
	 * create a row into the database (table options) to save the relashionship between file and number of pages, used in admin backend
	 * 
	 * 
	 */
	public function addOptionsNumberPagesToFile( $option, $f, $value )
	{
		if ( !empty( $f ))
		{
			$optionFromDb = get_option( $option );
			
			if ( $optionFromDb === false )
			{
				$opt = new stdClass();
				$opt->files = array( $f );
				$opt->tot_pages = array( $value );
			
				add_option( $option, json_encode( $opt ) );
			}
			else {
				$opt = json_decode( $optionFromDb );
			
				if ( !in_array( $f, $opt->files ))
				{
					array_push( $opt->files, $f );
					array_push( $opt->tot_pages, $value );
			
					update_option( $option, json_encode( $opt ) );
				}
			}	
		}
	}
	
	private function getAndConditionOptionHigherID()
	{
		$higher_id = get_option( $this->option_higher_id_delete_order );		
		if( !empty( $higher_id ))
		{
			return ' AND ID > ' . $higher_id;
		}
		
		return '';
	}
	
	private function getResultOldOrders()
	{
		global $wpdb;
		
		$date_from = $this->order_startdate_cleaning_treshold;
		$date_to = date("Y-m-d", strtotime( date( "Y-m-d", strtotime( date("Y-m-d") ) ) . $this->order_cleaning_treshold ) );
		$post_status = implode( "','", array( 'wc-completed' ));
		
		$q = sprintf( $this->q_check_old_orders,
				$wpdb->posts,
				$post_status,
				$date_from,
				$date_to,
				$this->getAndConditionOptionHigherID() );
		
		return array(
				'q' => $q,
				'date_from' => $date_from,
				'date_to' => $date_to,
				'rows' => $wpdb->get_results( $q )
		);
	}
	
	/*
	 * check if there are some order more old than $order_cleaning_treshold
	 *
	 */
	public function checkOldOrders()
	{
		$results = $this->getResultOldOrders();
		
		/*echo "<pre>";
		print_r($results);
		echo "</pre>";*/
		
		wp_send_json( array(
				'q' => 			$results['q'],
				'date_from' => 	$results['date_from'],
				'date_to' => 	$results['date_to'],
				'count_old_orders' => count( $results['rows'] ),
				'success' => true,
				'errors' => ''
		));
		
		die();
	}
	
	/*
	 * delete db reference and files from orders more old than $order_cleaning_treshold
	 *
	 */
	public function deleteOldOrders()
	{
		$results = $this->getResultOldOrders();
		
		$f = 0;
		$o = 0;
		$higherID = null; 
		foreach( $results['rows'] as $r )
		{
			if( empty( $higherID ))
			{
				$higherID = $r->ID;
			}			
			
			$order = new WC_Order( $r->ID );
			
			$order_date = new DateTime( $order->order_date );
			
			$dir = wp_get_upload_dir();
			
			$gf_dir = $dir['basedir'] . $this->tmp_path;
			$gf_root_dir = $dir['basedir'] . $this->tmp_root_path;
			
			$session_order = get_option( sprintf( $this->option_orderid_x_session, $r->ID ));
			
			if( $session_order )
			{
				$option_numberofpages_x_file = json_encode( get_option( sprintf( $this->option_numberofpages_x_file, $session_order )));
				
				$list_of_files = $option_numberofpages_x_file->files;
				
				foreach( $list_of_files as $file )
				{
					unlink( $gf_root_dir . $order_date->format( 'Y/m/d' ));
					
					if( unlink( $gf_dir . $file ))
					{
						$f++;
					}
				}
				
				if( delete_option( sprintf( $this->option_orderid_x_session, $r->ID )))
				{
					$o++;
				}
				if( delete_option( sprintf( $this->option_numberofpages_x_file, $session_order )))
				{
					$o++;
				}
			}
		}
		
		update_option( $this->option_higher_id_delete_order, $higherID );

		wp_send_json( array(
				'files_deleted' => $f,
				'options_deleted' => $o,
				'success' => true,
				'errors' => ''
		));
		
		die();
	}

	/*
	 * get the file to download
	 *
	 */

	public function downloadzipfile(){

		$order_id = absint( $_POST['data']['order'] );

		if ( ! $order_id ) {
			wp_send_json(array('errors' => true ,'success' => '','message' =>  'missing order number'));
			die();
		}

		$log_folder = sprintf( "%s/log/", dirname(__FILE__));
		$log_file = sprintf( "%sdownload-zip_orderid-%s_%s.txt", $log_folder, $order_id, date( 'Ymd-His' ));



		if( !file_exists( $log_folder ))
		{
			mkdir( $log_folder );
		}

		$zipFile = $this->make_zip( $order_id, $log_file );

		if ( file_exists( $zipFile ) ) {
			$_xlink = '<a href="'.wp_upload_dir()['baseurl'] .'/zip/'. basename( $zipFile ).'" download="'.basename( $zipFile ).'" class="button download redydownload">Ready For Download</a>';
			wp_send_json(array(
				'order' => $_POST['data']['order'],
				'link' => $_xlink,
				'success' => true,
				'errors' => ''
			));


			die();
		}else{
			wp_send_json(array('errors' => true ,'success' => '','message' =>  'zip file not found'));
			die();
		}
	}
	
	/*
	 * received the file names
	 * now find them and count pages per file
	 * return the total count back to front
	 *
	 */
	public function getPages() 
	{
		$this->checkValues();

		$exception = '';
		$jTableResult = array();
		$totalCount   = 0;

		require_once( get_stylesheet_directory() . '/fpdf/fpdf.php' );
		//require_once( get_stylesheet_directory() . '/fpdi/fpdi.php' );

		$dir    = wp_get_upload_dir();
		$gf_dir = $dir['basedir'] . $this->tmp_path;

		if ( $this->file_names ) 
		{
			$token = $this->getCustomtoken();
			
			$c = 0;
			foreach ( $this->file_names as $f ) 
			{
				// do a SEARCH for the given file name string (using wildcards)
				$list = array(); // reset
				// Use [pP][dD][fF] to catch insensitive string (Glob patterns support character ranges)
				$list = glob( $gf_dir . "*" . $f . ".[pP][dD][fF]" );

				// if we found a file, count it
				$tot = 1;
				if ( $list[0] ) 
				{
					$tot = $this->countPage( $list[0] );
					
					switch( $tot )
					{
						case 'file_encrypted':
							$totalCount = 0;
							$exception = __( 'Error: an encrypted file has been detected. Please remove the protection before uploading.' );
							break;
						default:
							$totalCount += $tot;
							$this->addOptionsNumberPagesToFile( sprintf( $this->option_numberofpages_x_file, $token ), sanitize_file_name($this->original_file_names[$c]), $tot );
							break;
					}
				}
				else {
					$arrExt = array(
						'jpg' => glob( $gf_dir . "*" . $f . ".[jJ][pP][gG]" ),
						'jpeg' => glob( $gf_dir . "*" . $f . ".[jJ][pP][eE][gG]" ),
						'dwg' => glob( $gf_dir . "*" . $f . ".[dD][wW][gG]" )
					);
					
					foreach ( $arrExt as $k => $v )
					{
						if ( $v[0] )
						{
							$totalCount += 1;
							
							$this->addOptionsNumberPagesToFile( sprintf( $this->option_numberofpages_x_file, $token ), sanitize_file_name($this->original_file_names[$c]), $tot );
							break;
						}
					}
				}
				$c++;
			}
		}

		//Return result to front
		//		$jTableResult['form']   = $this->form_id;
		//		$jTableResult['file']   = $file;

		$jTableResult['count'] = $totalCount;
		if( $exception != '' )
		{
			$jTableResult['exception'] = $exception;
		}
		
		//		$jTableResult['gf_dir'] = $gf_dir;
		print json_encode( $jTableResult );

		die();
	}

	/**
	 * receive the values from the frontend
	 * and clean them up
	 */
	private function checkValues() 
	{
		// TEST: what's inside the POST
		/*if ( get_option( 'aaaaaaaa' ) != false )
		{
			update_option( 'aaaaaaaa', serialize($_POST));
		}
		else {
			add_option( 'aaaaaaaa', serialize($_POST));
		}*/
		
		//$this->form_id    = esc_attr( $_POST['form_id'] );
		$this->file_names = $_POST['data']['file_names'];
		$this->original_file_names = $_POST['data']['original_file_names'];
		
		// check to see if the submitted nonce matches with the
		// generated nonce we created earlier
		$nonce = $_POST['postCommentNonce'];
		//if ( ! wp_verify_nonce( $nonce, 'dms-nonce' ) ) {
		//    die( 'Busted!!' );
		//}
	}

	/**
	 * find the actual count of the given file
	 * (using helpers FPDF / FPDI )
	 *
	 * @param $file
	 *
	 * @return int
	 */
	private function countPage( $file ) 
	{
		// initiate FPDI
		$pdf = new FPDI();
		
		// get the page count
		if ( file_exists( $file ) ) 
		{
			try {
				// at this point, $file it's always a PDF!
				return $pdf->setSourceFile( $file );
			}
			catch ( Exception $e ) {
				$exc_message = $e->getMessage();
				
				// echo "EXC MESSAGE ::: " . $exc_message;
				
				if( strpos( trim( strtolower( $exc_message )), 'file is encrypted' ) !== false )
				{
					return 'file_encrypted';
				}
			}
		}
		
		return 0;
	}

 /**
 *
 * create zipfile
 * get files and put them in
 *
 * @param $order_id
 * @param $log_file
 *
 * @return string
 */
	private function make_zip( $order_id, $log_file ) 
	{
		// zipfile location
		$zip_file = sprintf( '%s/zip/%s.zip', wp_upload_dir()['basedir'], $order_id );


		file_put_contents( $log_file, sprintf("ORDER ID::: %s\n", $order_id ), FILE_APPEND );
		file_put_contents( $log_file, sprintf("ZIP::: %s\n", $zip_file ), FILE_APPEND );
		
		//
		// delete previous zip file
		//
		if ( file_exists( $zip_file ) ) {
			unlink( $zip_file );
		}
		//
		// generate the zipfile with given files
		//
		$zip = new ZipArchive;
		$res = $zip->open( $zip_file, ZipArchive::CREATE );

		/*
		 * if we have a new zip file
		 * add the files to it
		 * and send it back
		 */
		if ( $res === TRUE ) 
		{
			/* get the files from the order */
			$files = $this->get_files( $order_id );



			/* add them to the zip */
			$item_compare = '';
			foreach ( $files as $file ) 
			{
				$url = 	$file['url'];
				$item = $file['item'];
				
				if( $item_compare != $item )
				{
					$item_compare = $item;
					
					file_put_contents( $log_file, sprintf("\nITEM::: %s\n", $item ), FILE_APPEND );
				}
				file_put_contents( $log_file, sprintf("%s\n", $url ), FILE_APPEND );
				
				/*
				 * FILE CONTENT
				 */
				$file_content = file_get_contents( $url );
				
				file_put_contents( $log_file, sprintf("FILE SIZE::: %s\n", strlen( $file_content ) ), FILE_APPEND );
				
				/*
				 * HTTPS issues: file images were empty, so if the result of file_put_contents is 0, I will use CURL
				 */
				if( strlen( $file_content ) == 0 )
				{
					$file_content = $this->curl_file_get_contents( $url );
					
					file_put_contents( $log_file, sprintf("FILE SIZE AFTER CURL::: %s\n", strlen( $file_content ) ), FILE_APPEND );
				}
				
				$zip->addFromString( 
						sprintf( 'order_%s/%s/%s', $order_id, $item, basename( $url )), 
						$file_content
				);
			}
		}

		$zip->close();
		
		return $zip_file;

	}

	/**
	 * get the files from the order
	 * and return array of file-location (and some meta)
	 */
	private function get_files( $order_id ) {

		$files = array();

		// Get an instance of the WC_Order object
		$order = wc_get_order( $order_id );
		$items = $order->get_items();

		// get the item, so we can
		foreach ( $items as $item_id => $item_data ) {

			if ( $item_data['File'] ) {

				$fileList_array = explode( ',', $item_data['File'] );
				if ( $fileList_array ) {
					foreach ( $fileList_array as $file ) {
						$dom = new DomDocument();
						$dom->loadHTML( $file );
						$output = array();
						foreach ($dom->getElementsByTagName('a') as $item) 
						{
							$output[] = array (
								'str' => $dom->saveHTML($item),
								'href' => $item->getAttribute('href'),
								'anchorText' => $item->nodeValue
							);
						}
						$href = trim( str_replace('\"', '', $output[0]['href'] ));
						
						$variations = array();
						array_push( $variations, $this->sanitizeString($item_data['Document Type'] ));
						array_push( $variations, $this->sanitizeString($item_data['Size'] ));
						array_push( $variations, $this->sanitizeString($item_data['Colours'] ));
						array_push( $variations, $this->sanitizeString($item_data['Finish'] ));
						if ( strlen( $item_data['Encapsulation'] ) > 0 )
						{
							array_push( $variations, $this->sanitizeString($item_data['Encapsulation'] ));
						}	
					
						// just get the href tag of it
						$files[] = array( 'url' => $href, 'item' => implode ( '-', $variations ));
					}
				}
			}
		}
		return $files;
	}	

	/**
	 *
	 * curl_file_get_contents zipfile
	 * https://stackoverflow.com/questions/27980682/php-empty-result-file-get-contents
	 *
	 * @param $url
	 *
	 * @return string
	 */
	private function curl_file_get_contents( $url )
	{
		$ch = curl_init();
		
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_REFERER, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		
		$contents = curl_exec( $ch );
		
		curl_close( $ch );
		
		return $contents;
	}

	/**
	 * sanitize string
	 */
	private function sanitizeString( $string ) {
		$string = str_replace(' ', '', $string);
		$string = str_replace('&#163;', 'PoundSterling', $string);
		$string = str_replace('&amp;', '', $string);

		return $string;
		//return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
	}		

}