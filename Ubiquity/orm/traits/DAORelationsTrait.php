<?php

namespace Ubiquity\orm\traits;

use Ubiquity\orm\OrmUtils;
use Ubiquity\orm\parser\ManyToManyParser;
use Ubiquity\orm\parser\ConditionParser;

/**
 * @author jc
 * @property \Ubiquity\db\Database $db
 */
trait DAORelationsTrait {
	abstract protected static function _getAll($className, ConditionParser $conditionParser, $included=true,$useCache=NULL);
	
	protected static function _affectsRelationObjects($manyToOneQueries,$oneToManyQueries,$manyToManyParsers,$objects,$included,$useCache){
		if(\sizeof($manyToOneQueries)>0){
			self::_affectsObjectsFromArray($manyToOneQueries,$included, function($object,$member,$manyToOneObjects,$fkField){
				self::affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField);
			});
		}		
		if(\sizeof($oneToManyQueries)>0){
			self::_affectsObjectsFromArray($oneToManyQueries,$included, function($object,$member,$relationObjects,$fkField){
				self::affectsOneToManyFromArray($object,$member,$relationObjects,$fkField);
			});
		}
		if(\sizeof($manyToManyParsers)>0){
			self::_affectsManyToManyObjectsFromArray($manyToManyParsers, $objects,$included,$useCache);
		}
	}
	
	private static function affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField){
		$class=\get_class($object);
		if(isset($object->$fkField)){
			$value=$manyToOneObjects[$object->$fkField];
			self::setToMember($member, $object, $value, $class, "getManyToOne");
		}
	}
	
	/**
	 * @param object $instance
	 * @param string $member
	 * @param array $array
	 * @param string $mappedBy
	 */
	private static function affectsOneToManyFromArray($instance, $member, $array=null, $mappedBy=null) {
		$ret=array ();
		$class=get_class($instance);
		if (!isset($mappedBy)){
			$annot=OrmUtils::getAnnotationInfoMember($class, "#oneToMany", $member);
			$mappedBy=$annot["mappedBy"];
		}
		if ($mappedBy !== false) {
			$fkv=OrmUtils::getFirstKeyValue($instance);
			self::_getOneToManyFromArray($ret, $array, $fkv, $mappedBy);
			self::setToMember($member, $instance, $ret, $class, "getOneToMany");
		}
		return $ret;
	}
	
	private static function _affectsObjectsFromArray($queries,$included,$affectsCallback,$useCache=NULL){
		$includedNext=false;
		foreach ($queries as $key=>$pendingRelationsRequest){
			list($class,$member,$fkField)=\explode("|", $key);
			if(is_array($included)){
				$includedNext=self::_getIncludedNext($included, $member);
			}
			$objectsParsers=$pendingRelationsRequest->getObjectsConditionParsers();
			foreach ($objectsParsers as $objectsConditionParser){
				$objectsConditionParser->compileParts();
				$relationObjects=self::_getAll($class,$objectsConditionParser->getConditionParser(),$includedNext,$useCache);
				$objects=$objectsConditionParser->getObjects();
				foreach ($objects as $object){
					$affectsCallback($object, $member,$relationObjects,$fkField);
				}
			}
		}
	}
	
	private static function _affectsManyToManyObjectsFromArray($parsers,$objects,$included,$useCache=NULL){
		$includedNext=false;
		foreach ($parsers as $key=>$parser){
			list($class,$member)=\explode("|", $key);
			if(is_array($included)){
				$includedNext=self::_getIncludedNext($included, $member);
			}
			$myPkValues=[];
			$cParser=self::generateManyToManyParser($parser, $myPkValues);
			$relationObjects=self::_getAll($class,$cParser,$includedNext,$useCache);
			$oClass=get_class(reset($objects));
			foreach ($objects as $object){
				$pkV=OrmUtils::getFirstKeyValue($object);
				if(isset($myPkValues[$pkV])){
					$ret=self::getManyToManyFromArrayIds($relationObjects, $myPkValues[$pkV]);
					self::setToMember($member, $object, $ret, $oClass, "getManyToMany");
				}
			}
		}
	}
	
	private static function generateManyToManyParser(ManyToManyParser $parser,&$myPkValues){
		$sql=$parser->generateConcatSQL();
		$result=self::$db->prepareAndFetchAll($sql,$parser->getWhereValues());
		$condition=$parser->getParserWhereMask(" ?");
		$cParser=new ConditionParser();		
		foreach ($result as $row){
			$values=explode(",", $row["_concat"]);
			$myPkValues[$row["_field"]]=$values;
			$cParser->addParts($condition, $values);
		}
		$cParser->compileParts();
		return $cParser;
	}
	
	private static function _getIncludedNext($included,$member){
		return (isset($included[$member]))?(is_bool($included[$member])?$included[$member]:[$included[$member]]):false;
	}
	
	private static function getManyToManyFromArrayIds($relationObjects, $ids){
		$ret=[];
		foreach ( $relationObjects as $targetEntityInstance ) {
			$id=OrmUtils::getFirstKeyValue($targetEntityInstance);
			if (array_search($id, $ids)!==false) {
				array_push($ret, $targetEntityInstance);
			}
		}
		return $ret;
	}
	
	protected static function getIncludedForStep($included){
		if(is_bool($included)){
			return $included;
		}
		$ret=[];
		if(is_array($included)){
			foreach ($included as &$includedMember){
				if(is_array($includedMember)){
					foreach ($includedMember as $iMember){
						self::parseEncludeMember($ret, $iMember);
					}
				}else{
					self::parseEncludeMember($ret, $includedMember);
				}
			}
		}
		return $ret;
	}
	
	private static function parseEncludeMember(&$ret,$includedMember){
		$array=explode(".", $includedMember);
		$member=array_shift($array);
		if(sizeof($array)>0){
			$newValue=implode(".", $array);
			if($newValue==='*'){
				$newValue=true;
			}
			if(isset($ret[$member])){
				if(!is_array($ret[$member])){
					$ret[$member]=[$ret[$member]];
				}
				$ret[$member][]=$newValue;
			}else{
				$ret[$member]=$newValue;
			}
		}else{
			if(isset($member) && ""!=$member){
				$ret[$member]=false;
			}else{
				return;
			}
		}
	}
	
	private static function getInvertedJoinColumns($included,&$invertedJoinColumns){
		foreach ($invertedJoinColumns as $column=>&$annot){
			$member=$annot["member"];
			if(isset($included[$member])===false){
				unset($invertedJoinColumns[$column]);
			}
		}
	}
	
	private static function getToManyFields($included,&$toManyFields){
		foreach ($toManyFields as $member=>$annotNotUsed){
			if(isset($included[$member])===false){
				unset($toManyFields[$member]);
			}
		}
	}
	
	protected static function _initRelationFields($included,$metaDatas,&$invertedJoinColumns,&$oneToManyFields,&$manyToManyFields){
		if (isset($metaDatas["#invertedJoinColumn"])){
			$invertedJoinColumns=$metaDatas["#invertedJoinColumn"];
		}
		if (isset($metaDatas["#oneToMany"])) {
			$oneToManyFields=$metaDatas["#oneToMany"];
		}
		if (isset($metaDatas["#manyToMany"])) {
			$manyToManyFields=$metaDatas["#manyToMany"];
		}
		if(is_array($included)){
			if(isset($invertedJoinColumns)){
				self::getInvertedJoinColumns($included, $invertedJoinColumns);
			}
			if(isset($oneToManyFields)){
				self::getToManyFields($included, $oneToManyFields);
			}
			if(isset($manyToManyFields)){
				self::getToManyFields($included, $manyToManyFields);
			}
		}
	}
}
