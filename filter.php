<?php
namespace forumedia\news;

use \Bitrix\Main\Loader,
    \Bitrix\Main\Entity\Query;
/**
 * Класс для формирования фильтра новостей
 *
 * @author Aleksandr Kiselev
 * @link https://www.forumedia.ru/
 */

class Filter{
	const IBLOCK_ID = 400;
    const PROPERTY_ELEMENT_TYPE_ID = 2454;
    const LANGUAGE_LIST_CACHE_TIME = 864000;
    const SET_DEFAULT_LANGUAGE = 'ru';

	private $filter, $filterTags, $user, $data, $list, $elementType;

	public function __construct(array $params){
		Loader::includeModule('iblock');
        Loader::includeModule('forumedia.common');

		self::initParams($params);
	}

	public function getFilter():Query\Filter\ConditionTree{
		$this->filter = Query::filter();

		if($this->data->isActive){
			$this->filter->where('ACTIVE', '=', $this->data->isActive);
		}

        if(!is_null($this->data->activeFrom)){
            $this->filter->where('ACTIVE_FROM', '>=', $this->data->activeFrom);        
        }

        if(!is_null($this->data->activeTo)){
            $this->filter->where('ACTIVE_FROM', '<=', $this->data->activeTo);
        }

        if($this->elementType != 'all')
            $this->filter->whereIn('ELEMENT_TYPE.ITEM.ID', $this->data->elementType); 

        // if($this->data->exclude->id)
        //     $this->filter->whereNot('ID', $this->data->exclude->id);

        
        
        if($this->data->language->id)
            $this->filter->where('LANGUAGE_VERSION.ELEMENT.ID', '=', $this->data->language->id);

        if($this->data->dateAnonceStart)
            $this->filter->where('DATE_ANONCE_START.VALUE', '>=', new \Bitrix\Main\Type\DateTime);

        // Фильтра по тегу или по разделу
        // if($this->data->tags && !empty($this->data->section)){			
		// 	$this->filterTags = $this->filter->getConditions();

		// 	// $this->filterTags = Query::filter()->where('IBLOCK_ID', '=', self::IBLOCK_ID);
		// 	$this->filterTags->whereIn('ID', \forumedia\common\iblock::searchByTags($this->data->tags, self::IBLOCK_ID));
			
		// 	$this->filter->where($this->data->sectionType, '=', $this->data->section);

		// 	return Query::filter()
		// 		->logic('or')
		// 		->where($this->filter)
		// 		->where($this->filterTags);
        // }

        // if($GLOBALS['USER']->GetID() == 7249 || $GLOBALS['USER']->GetID() == 5565) {
            $query = Query::filter();

            if($this->data->tags) {
                $idsByTags = \forumedia\common\iblock::searchByTags($this->data->tags, self::IBLOCK_ID);
                $idsByTags = array_diff($idsByTags, $this->data->exclude->id ?: []);

                $query->whereIn('ID', $idsByTags);
            }

            if($this->data->section) {
                $query->where($this->data->sectionType, '=', $this->data->section);
            }

            if($this->data->section && $this->data->tags) {
                $query->logic('or');
            }

            $query2 = Query::filter();
            if($this->data->exclude->id)
                $query2->whereIn('ID', $this->data->exclude->id)->negative();
            // $query2->where($query);
            

            
            $this->filter->where($query);
            // $this->filter->where($query2);
            // if($GLOBALS['USER']->GetID() == 7249) {
            //     vdump($this->filter);
            // }

            return $this->filter;
        // } else {

        //     // Фильтр только по тегам
        //     if($this->data->tags){
        //         $idsByTags = \forumedia\common\iblock::searchByTags($this->data->tags, self::IBLOCK_ID);
        //         $idsByTags = array_diff($idsByTags, $this->data->exclude->id ?: []);
    
        //         $this->filter->whereIn('ID', $idsByTags);
        //         return $this->filter;
        //     }
            
        //     // Фильтр только по разделу
        //     if($this->data->section)
        //         $this->filter->where($this->data->sectionType, '=', $this->data->section);
        //     else
        //         throw new \Exception("Section is not defined in news filter module", 1);
        // }
            

        
        return $this->filter;
	}

	protected function initParams(array $params = []){
		$this->user->isAdmin = $GLOBALS['USER']->IsAdmin();
		$this->user->isGlobalModerator = \forumedia\common\subdomain::isGlobalModerator();
		$this->user->isResourcesModerator = \forumedia\common\subdomain::isModerator();

		$this->data->isActive	= (!$this->user->isGlobalModerator || ($params['ONLY_ACTIVE'] === 'Y')) ?: null;

		$this->data->activeFrom	= $params['ACTIVE_FROM'] ? \Bitrix\Main\Type\DateTime::createFromTimestamp($params['ACTIVE_FROM']) : null;
		$this->data->activeTo	= $params['ACTIVE_TO']   ? \Bitrix\Main\Type\DateTime::createFromTimestamp($params['ACTIVE_TO'])   : null;

        self::initElementTypeList();
        if($params['ELEMENT_TYPE'] == 'all'){
            $this->elementType = 'all';
            $this->data->elementType    = $this->list->elementTypeAll;
        }else{
            $this->data->elementType    = $this->list->elementType->{$params['ELEMENT_TYPE']}  ?: $this->list->elementType->news; //default is element type - news
        }

        if($params['SECTION']){
			if(!is_numeric($params['SECTION'])){
				$this->data->section = $params['SECTION'];
				$this->data->sectionType = 'IBLOCK_SECTION.CODE';
			}else{
				$this->data->section = intval($params['SECTION']);
				$this->data->sectionType = 'IBLOCK_SECTION.ID';
			}
        }

        self::initLanguageList();
        $langCode = ($params['LANGUAGE'] ?: \LANGUAGE_ID) ?: self::SET_DEFAULT_LANGUAGE;
        $this->data->language->id = $this->list->languages->{$langCode};
        $this->data->language->code = $langCode;
        
        // $this->data->resources->id = self::initResources($params['RESOURCES']);

        $this->data->exclude->id = $params['EXCLUDE_ID'];

        // if($this->arResult['VARIABLES']['TYPE'] == 'announcement'){
        if($params['DATE_ANONCE_START'] === true)
            $this->data->dateAnonceStart = true; 
        
        if($params['FILTER_TAGS'] && is_array($params['FILTER_TAGS'])) {
            $this->data->tags = $params['FILTER_TAGS'];
        }
	}

	protected function initElementTypeList(){
        if(empty($this->list->elementType)){
            $collection = \Bitrix\Iblock\PropertyEnumerationTable::getList(array(
                'filter' => array('PROPERTY_ID' => self::PROPERTY_ELEMENT_TYPE_ID)
            ))->fetchCollection();
    
            foreach ($collection as $enum) {
                $this->list->elementTypeAll[] = $enum->getId();
                $this->list->elementType->{$enum->getXmlId()} = $enum->getId();
            }
        }
	}

    protected function initLanguageList(){
        if(empty($this->list->languages)){
            $collection = \Bitrix\Iblock\Elements\ElementLanguageTable::getList([
                'select' => ['ID', 'CODE'],
                'filter' => ['=ACTIVE' => true],
                'cache' => ['ttl' => self::LANGUAGE_LIST_CACHE_TIME] //10 дней
            ])->fetchCollection();
    
            foreach ($collection as $language) {
                $this->list->languages->{$language->getCode()} = $language->getId();
            }
        }
    }

    // protected function initResources(string $params = null){
    //     return (\forumedia\common\subdomain::getCurrentResources($params))['ID'] ?: null;
    // }

    public static function getDefaultSelect():array{
        return [
            'ID',
            'NAME',
            'ACTIVE',
            'SECTION_ID' => 'IBLOCK_SECTION.ID',
            'SECTION_CODE' => 'IBLOCK_SECTION.CODE',
            'DETAIL_PICTURE',
            'PREVIEW_PICTURE',
            'DATE_CREATE',
            'ACTIVE_FROM',
            'ELEMENT_TYPE_XML_ID' => 'ELEMENT_TYPE.ITEM.XML_ID',
            'DETAIL_TEXT',
            'LANGUAGE_VERSION_CODE' => 'LANGUAGE_VERSION.ELEMENT.CODE',
            'PLAYER_VALUE' => 'PLAYER.VALUE',
            'DATE_ANONCE_START_VALUE' => 'DATE_ANONCE_START.VALUE',
            'TAGS'
        ];
    }

    public static function getDefaultOrder():array{
        return [
            'DATE_CREATE' => 'DESC',
            'ACTIVE_FROM' => 'DESC'
        ];
    }

    public static function getDefaultCache():array{
        return [
            'ttl' => 1800,
            'cache_joins' => true,
        ];
    }
}
