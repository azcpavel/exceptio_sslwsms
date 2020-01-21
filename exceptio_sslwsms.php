<?php
/*
*   2020 Exception Solutions.
*
*   @author Exception Solutions <azc.pavel@gmail.com>
*   @copyright  2020 Exception Solutions
*   @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*   @abstract To send sms with SSL Wireless
*/
require "..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."config".DIRECTORY_SEPARATOR."config.inc.php";
if (!defined('_PS_VERSION_')) {
    exit;
}
class exceptio_sslwsms extends Module
{
    private $sslUserId = "";
    private $sslUserPass = "";
    private $sslUserSID = "";

    public $appParam;

    public function __construct()
    {
        $this->name = 'exceptio_sslwsms';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.0';
        $this->author = 'ExceptionSolutions';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->trans('EXCEPTIO SSL WIRELESS SMS', array(), 'Modules.exceptio_sslwsms.Admin');
        $this->description = $this->trans('Send SMS on payment complete.', array(), 'Modules.exceptio_sslwsms.Admin');
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);        
    }

    public function install()
    {
        return parent::install() && $this->registerHook('AdminEXCEPTIOSSLWsmsModules');
    }    

    public function hookAdminEXCEPTIOSSLWsmsModules($params)
    {
        
    }

    public function setup(){
    	$data = Db::getInstance()->executeS('SHOW COLUMNS FROM `'._DB_PREFIX_.'orders` LIKE "exceptio_sslwsms"');
    	if(count($data) < 1){    		
    		Db::getInstance()->execute('
    			ALTER TABLE `'._DB_PREFIX_.'orders`
    			ADD COLUMN exceptio_sslwsms int default 0
    			');    		    		
    		Db::getInstance()->update('orders',array(
    			'exceptio_sslwsms' => 1
    		),'id_order != 0');
    	}

    	$data = Db::getInstance()->executeS('SHOW COLUMNS FROM `'._DB_PREFIX_.'customer` LIKE "phone"');
    	if(count($data) < 1){
    		       
            if(!file_exists("..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."override")){
                mkdir($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override");
            }
            if(!file_exists("..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."override".DIRECTORY_SEPARATOR."controllers")){
                mkdir($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."controllers");
            }
            if(!file_exists("..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."override".DIRECTORY_SEPARATOR."controllers".DIRECTORY_SEPARATOR."admin")){
                mkdir($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."controllers".DIRECTORY_SEPARATOR."admin");
            }
            if(!file_exists("..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."override".DIRECTORY_SEPARATOR."classes")){
                mkdir($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."classes");
            }
            if(!file_exists("..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR."form")){
                mkdir($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR."form");
            }

            copy("override".DIRECTORY_SEPARATOR."controllers".DIRECTORY_SEPARATOR."admin".DIRECTORY_SEPARATOR.'AdminCustomersController.php', $_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."controllers".DIRECTORY_SEPARATOR."admin".DIRECTORY_SEPARATOR.'AdminCustomersController.php');
            copy("override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.'Customer.php', $_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.'Customer.php');
            copy("override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR."form".DIRECTORY_SEPARATOR.'CustomerFormatter.php', $_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.__PS_BASE_URI__."override".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR."form".DIRECTORY_SEPARATOR.'CustomerFormatter.php');

            Db::getInstance()->execute('
                ALTER TABLE `'._DB_PREFIX_.'customer`
                ADD COLUMN phone varchar(25) default null
                ');
    	} 
    }

    public function sendSMSinQue(){
    	$this->setup();
    	$sql = new DbQuery();
    	$sql->select('o.*,c.firstname, c.lastname, c.email, c.phone');
    	$sql->from('orders', 'o');
    	$sql->innerJoin('customer', 'c', 'o.id_customer = c.id_customer');
    	$sql->where('o.exceptio_sslwsms = 0');
    	$sql->orderBy('o.id_order','ASC');
    	$data = Db::getInstance()->executeS($sql);        
    	foreach ($data as $key => $value) {
            if($value['phone'] != ""){
                $value['phone'] = str_replace(" ", "", $value['phone']);
                if(!preg_match('/^\+880/', $value['phone']) && strlen($value['phone']) == 11)
                    $value['phone'] = '+88'.$value['phone'];
                if(strlen($value['phone']) == 14 && $value['valid'] == 1){                   
                    $action = 'https://sms.sslwireless.com/pushapi/dynamic/server.php?';
                    $params = array(
                        'user'      => $this->sslUserId,
                        'pass'      => $this->sslUserPass,
                        'sid'       => $this->sslUserSID,
                        'sms'       => "Your order confirmation number is ".$value['reference'].". Your order will be shipped in 3 days. Thanks for shopping with Bella BD.",
                        'msisdn'    => $value['phone'],
                        'csmsid'    => $value['reference']
                    );
                    
                    $curl = curl_init();
                    curl_setopt_array($curl, array( 
                        CURLOPT_RETURNTRANSFER => 1, 
                        CURLOPT_URL => $action.http_build_query($params), 
                        CURLOPT_USERAGENT => 'EXCEPTIO SSLWSMS cURL Request' ));
                    $resp = curl_exec($curl);                    
                    curl_close($curl);
                    if(preg_match('/<PERMITTED>OK<\/PERMITTED>/', $resp)){
                        $this->updateStatus($value);
                    }

                    $this->log_write($resp,1);                    
                }
                else
                    $this->updateStatus($value,2);
                
            }else{
                $this->updateStatus($value,2);
            }   	    	
    	}    	
    }

    private function updateStatus($order, $status = 1){
        Db::getInstance()->update('orders',array(
            'exceptio_sslwsms' => $status
        ),"id_order=".$order['id_order']);
    }

    private function log_write($content, $printTime = false, $fileName = "log.txt"){    
        if(!file_exists(__DIR__.DIRECTORY_SEPARATOR.$fileName)){
            $fh = fopen(__DIR__.DIRECTORY_SEPARATOR.$fileName, 'a');
            if($printTime)
                fwrite($fh, date('Y-m-d H:i:s')."# ".$content.PHP_EOL);
            else
                fwrite($fh, $content.PHP_EOL);
            fclose($fh);
        }else{
            $fh = fopen(__DIR__.DIRECTORY_SEPARATOR.$fileName, 'a');
            if($printTime)
                fwrite($fh, date('Y-m-d H:i:s')."# ".$content.PHP_EOL);
            else
                fwrite($fh, $content.PHP_EOL);
            fclose($fh);
        }
    }
}
$exceptio_sslwsmsObj = new exceptio_sslwsms();
$exceptio_sslwsmsObj->sendSMSinQue();