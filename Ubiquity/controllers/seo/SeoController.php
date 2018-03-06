<?php

namespace Ubiquity\controllers\seo;

use Ubiquity\controllers\Controller;
use Ubiquity\seo\UrlParser;
use Ubiquity\utils\http\Response;
use Ubiquity\cache\CacheManager;
use Ubiquity\controllers\Startup;
use Ubiquity\utils\base\UArray;

class SeoController extends Controller {
	const SEO_PREFIX="seo";
	protected $urlsKey="urls";
	protected $seoTemplateFilename="Seo/sitemap.xml.html";

	public function index() {
		$config=Startup::getConfig();
		$base=\rtrim($config['siteUrl'],'/');
		Response::asXml();
		Response::noCache();
		$urls=$this->_getArrayUrls();
		if(\is_array($urls)){
			$parser=new UrlParser();
			$parser->parseArray($urls);
			$this->loadView($this->seoTemplateFilename,["urls"=>$parser->getUrls(),"base"=>$base]);
		}
	}

	public function _refresh(){

	}

	public function _save($array){
		CacheManager::$cache->store($this->_getUrlsFilename(),'return '.UArray::asPhpArray($array,"array").';');
	}

	public function _getArrayUrls(){
		$key=$this->_getUrlsFilename();
		if(!CacheManager::$cache->exists($key)){
			$this->_save([]);
		}
		return CacheManager::$cache->fetch($key);
	}

	/**
	 * @return string
	 */
	public function _getUrlsFilename() {
		return self::SEO_PREFIX.DS.$this->urlsKey;
	}

	/**
	 * @return string
	 */
	public function _getSeoTemplateFilename() {
		return $this->seoTemplateFilename;
	}

}
