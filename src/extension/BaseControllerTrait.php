<?php
namespace rjapi\extension;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use League\Fractal\Resource\Collection;
use Psy\Configuration;
use rjapi\helpers\ConfigOptions;
use rjapi\helpers\Jwt;
use rjapi\types\ConfigInterface;
use rjapi\types\DirsInterface;
use rjapi\blocks\EntitiesTrait;
use rjapi\blocks\FileManager;
use rjapi\types\JwtInterface;
use rjapi\types\ModelsInterface;
use rjapi\types\RamlInterface;
use rjapi\helpers\Classes;
use rjapi\helpers\Config;
use rjapi\helpers\Json;
use rjapi\helpers\MigrationsHelper;
use rjapi\helpers\SqlOptions;
use rjapi\types\PhpInterface;

/**
 * Class BaseControllerTrait
 *
 * @package rjapi\extension
 */
trait BaseControllerTrait
{
    use BaseModelTrait, EntitiesTrait;

    private $props = [];
    private $entity = null;
    /** @var BaseModel model */
    private $model = null;
    private $modelEntity = null;
    private $middleWare = null;
    private $relsRemoved = false;
    // default query params value
    private $defaultPage = 0;
    private $defaultLimit = 0;
    private $defaultSort = '';
    private $defaultOrderBy = [];
    /** @var ConfigOptions configOptions */
    private $configOptions = null;

    private $jsonApiMethods = [
        JSONApiInterface::URI_METHOD_INDEX,
        JSONApiInterface::URI_METHOD_VIEW,
        JSONApiInterface::URI_METHOD_CREATE,
        JSONApiInterface::URI_METHOD_UPDATE,
        JSONApiInterface::URI_METHOD_DELETE,
        JSONApiInterface::URI_METHOD_RELATIONS,
    ];

    private $jwtExcluded = [
        JwtInterface::JWT,
        JwtInterface::PASSWORD,
    ];

    /**
     * BaseControllerTrait constructor.
     *
     * @param Route $route
     */
    public function __construct(Route $route)
    {
        // add relations to json api methods array
        $this->addRelationMethods();
        $actionName = $route->getActionName();
        $calledMethod = substr($actionName, strpos($actionName, PhpInterface::AT) + 1);
        /** @var BaseController jsonApi */
        if($this->jsonApi === false && in_array($calledMethod, $this->jsonApiMethods))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE  => 'JSON API support disabled',
                        JSONApiInterface::ERROR_DETAIL => 'JSON API method ' . $calledMethod
                            .
                            ' was called. You can`t call this method while JSON API support is disabled.',
                    ],
                ]
            );
        }
        $this->setEntities();
        $this->setDefaults();
        $this->setConfigOptions();
    }

    /**
     * GET Output all entries for this Entity with page/limit pagination support
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $sqlOptions = $this->setSqlOptions($request);
        $items = $this->getAllEntities($sqlOptions);
        $resource = Json::getResource($this->middleWare, $items, $this->entity, true);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $sqlOptions->getData());
    }

    /**
     * GET Output one entry determined by unique id as uri param
     *
     * @param Request $request
     * @param int $id
     */
    public function view(Request $request, int $id)
    {
        $data = ($request->input(ModelsInterface::PARAM_DATA) === null) ? ModelsInterface::DEFAULT_DATA
            : json_decode(urldecode($request->input(ModelsInterface::PARAM_DATA)), true);
        $item = $this->getEntity($id, $data);
        $resource = Json::getResource($this->middleWare, $item, $this->entity);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $data);
    }

    /**
     * POST Creates one entry specified by all input fields in $request
     *
     * @param Request $request
     */
    public function create(Request $request)
    {
        $json = Json::decode($request->getContent());
        $jsonApiAttributes = Json::getAttributes($json);
        foreach($this->props as $k => $v)
        {
            // request fields should match Middleware fields
            if(isset($jsonApiAttributes[$k]))
            {
                $this->model->$k = $jsonApiAttributes[$k];
            }
        }
        $this->model->save();
        // jwt
        if($this->configOptions->getIsJwtAction() === true)
        {
            if(empty($this->model->password))
            {
                Json::outputErrors(
                    [
                        [
                            JSONApiInterface::ERROR_TITLE  => 'Password should be provided',
                            JSONApiInterface::ERROR_DETAIL => 'To get refreshed token in future usage of application - user password should be provided',
                        ],
                    ]
                );
            }
            $uniqId = uniqid();
            $model = $this->getEntity($this->model->id);
            $model->jwt = Jwt::create($this->model->id, $uniqId);
            $model->password = password_hash($this->model->password, PASSWORD_DEFAULT);
            $model->save();
            $this->model = $model;
            unset($this->model->password);
        }
        $this->setRelationships($json, $this->model->id);
        $resource = Json::getResource($this->middleWare, $this->model, $this->entity);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);
    }

    /**
     * PATCH Updates one entry determined by unique id as uri param for specified fields in $request
     *
     * @param Request $request
     * @param int $id
     */
    public function update(Request $request, int $id)
    {
        // get json raw input and parse attrs
        $json = Json::decode($request->getContent());
        $jsonApiAttributes = Json::getAttributes($json);
        $model = $this->getEntity($id);
        // jwt
        if($this->configOptions->getIsJwtAction() === true && (bool)$request->jwt === true)
        {
            if(password_verify($jsonApiAttributes[JwtInterface::PASSWORD], $model->password) === false)
            {
                Json::outputErrors(
                    [
                        [
                            JSONApiInterface::ERROR_TITLE  => 'Password is invalid.',
                            JSONApiInterface::ERROR_DETAIL => 'To get refreshed token - pass the correct password',
                        ],
                    ]
                );
            }
            $uniqId = uniqid();
            $model->jwt = Jwt::create($model->id, $uniqId);
            unset($model->password);
        }
        else
        { // standard processing
            foreach($this->props as $k => $v)
            {
                // request fields should match Middleware fields
                if(empty($jsonApiAttributes[$k]) === false)
                {
                    $model->$k = $jsonApiAttributes[$k];
                }
            }
        }
        $model->save();
        $this->setRelationships($json, $model->id, true);
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * DELETE Deletes one entry determined by unique id as uri param
     *
     * @param int $id
     */
    public function delete(int $id)
    {
        $model = $this->getEntity($id);
        if($model !== null)
        {
            $model->delete();
        }
        Json::outputSerializedData(new Collection(), JSONApiInterface::HTTP_RESPONSE_CODE_NO_CONTENT);
    }

    /**
     * GET the relationships of this particular Entity
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function relations(Request $request, int $id, string $relation)
    {
        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getRelations($model->$relation, $relation);
        Json::outputSerializedRelations($request, $resource);
    }

    /**
     * POST relationships for specific entity id
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function createRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $this->setRelationships($json, $id);

        $_GET['include'] = $relation;
        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * PATCH relationships for specific entity id
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function updateRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $this->setRelationships($json, $id, true);
        // set include for relations
        $_GET['include'] = $relation;

        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * DELETE relationships for specific entity id
     *
     * @param Request $request JSON API formatted string
     * @param int $id int id of an entity
     * @param string $relation
     */
    public function deleteRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $jsonApiRels = Json::getData($json);
        if(empty($jsonApiRels) === false)
        {
            $lowEntity = strtolower($this->entity);
            foreach($jsonApiRels as $index => $val)
            {
                $rId = $val[RamlInterface::RAML_ID];
                // if pivot file exists then save
                $ucEntity = ucfirst($relation);
                $file = DirsInterface::MODULES_DIR . PhpInterface::SLASH
                    . Config::getModuleName() . PhpInterface::SLASH .
                    DirsInterface::ENTITIES_DIR . PhpInterface::SLASH .
                    $this->entity . $ucEntity . PhpInterface::PHP_EXT;
                if(file_exists(PhpInterface::SYSTEM_UPDIR . $file))
                { // ManyToMany rel
                    $pivotEntity = Classes::getModelEntity($this->entity . $ucEntity);
                    // clean up old links
                    $this->getModelEntities(
                        $pivotEntity,
                        [
                            [
                                $lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID => $id,
                                $relation . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID  => $rId,
                            ],
                        ]
                    )->delete();
                }
                else
                { // OneToOne/Many
                    $relEntity = Classes::getModelEntity($ucEntity);
                    $model = $this->getModelEntities(
                        $relEntity, [
                            $lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID, $id,
                        ]
                    );
                    $model->update([$relation . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID => 0]);
                }
            }
            Json::outputSerializedData(new Collection(), JSONApiInterface::HTTP_RESPONSE_CODE_NO_CONTENT);
        }
    }

    /**
     * @param array $json
     * @param int $eId
     * @param bool $isRemovable
     */
    private function setRelationships(array $json, int $eId, bool $isRemovable = false)
    {
        $jsonApiRels = Json::getRelationships($json);
        if(empty($jsonApiRels) === false)
        {
            foreach($jsonApiRels as $entity => $value)
            {
                if(empty($value[RamlInterface::RAML_DATA][RamlInterface::RAML_ID]) === false)
                {
                    // if there is only one relationship
                    $rId = $value[RamlInterface::RAML_DATA][RamlInterface::RAML_ID];
                    $this->saveRelationship($entity, $eId, $rId, $isRemovable);
                }
                else
                {
                    // if there is an array of relationships
                    foreach($value[RamlInterface::RAML_DATA] as $index => $val)
                    {
                        $rId = $val[RamlInterface::RAML_ID];
                        $this->saveRelationship($entity, $eId, $rId, $isRemovable);
                    }
                }
            }
        }
    }

    /**
     * @param      $entity
     * @param int $eId
     * @param int $rId
     * @param bool $isRemovable
     */
    private function saveRelationship($entity, int $eId, int $rId, bool $isRemovable = false)
    {
        $ucEntity = Classes::getClassName($entity);
        $lowEntity = MigrationsHelper::getTableName($this->entity);
        // if pivot file exists then save
        $filePivot = FileManager::getPivotFile($this->entity, $ucEntity);
        $filePivotInverse = FileManager::getPivotFile($ucEntity, $this->entity);
        $pivotExists = file_exists(PhpInterface::SYSTEM_UPDIR . $filePivot);
        $pivotInverseExists = file_exists(PhpInterface::SYSTEM_UPDIR . $filePivotInverse);
        if($pivotExists === true || $pivotInverseExists === true)
        { // ManyToMany rel
            $pivotEntity = null;

            if($pivotExists)
            {
                $pivotEntity = Classes::getModelEntity($this->entity . $ucEntity);
            }
            else
            {
                if($pivotInverseExists)
                {
                    $pivotEntity = Classes::getModelEntity($ucEntity . $this->entity);
                }
            }

            if($isRemovable === true)
            {
                $this->clearPivotBeforeSave($pivotEntity, $lowEntity, $eId);
            }
            $this->savePivot($pivotEntity, $lowEntity, $entity, $eId, $rId);
        }
        else
        { // OneToOne
            $this->saveModel($ucEntity, $lowEntity, $eId, $rId);
        }
    }

    /**
     * @param string $pivotEntity
     * @param string $lowEntity
     * @param int $eId
     */
    private function clearPivotBeforeSave(string $pivotEntity, string $lowEntity, int $eId)
    {
        if($this->relsRemoved === false)
        {
            // clean up old links
            $this->getModelEntities(
                $pivotEntity,
                [$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID, $eId]
            )->delete();
            $this->relsRemoved = true;
        }
    }

    /**
     * @param string $pivotEntity
     * @param string $lowEntity
     * @param string $entity
     * @param int $eId
     * @param int $rId
     */
    private function savePivot(string $pivotEntity, string $lowEntity, string $entity, int $eId, int $rId)
    {
        $pivot = new $pivotEntity();
        $pivot->{$entity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $rId;
        $pivot->{$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $eId;
        $pivot->save();
    }

    /**
     * @param string $ucEntity
     * @param string $lowEntity
     * @param int $eId
     * @param int $rId
     */
    private function saveModel(string $ucEntity, string $lowEntity, int $eId, int $rId)
    {
        $relEntity =
            Classes::getModelEntity($ucEntity);
        $model =
            $this->getModelEntity($relEntity, $rId);
        $model->{$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $eId;
        $model->save();
    }

    /**
     *  Adds {HTTPMethod}Relations to array of route methods
     */
    private function addRelationMethods()
    {
        $ucRelations = ucfirst(JSONApiInterface::URI_METHOD_RELATIONS);
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_CREATE . $ucRelations;
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_UPDATE . $ucRelations;
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_DELETE . $ucRelations;
    }

    private function setDefaults()
    {
        $this->defaultPage = Config::getQueryParam(ModelsInterface::PARAM_PAGE);
        $this->defaultLimit = Config::getQueryParam(ModelsInterface::PARAM_LIMIT);
        $this->defaultSort = Config::getQueryParam(ModelsInterface::PARAM_SORT);
    }

    /**
     * Sets SqlOptions params
     * @param Request $request
     * @return SqlOptions
     */
    private function setSqlOptions(Request $request)
    {
        $sqlOptions = new SqlOptions();
        $page = ($request->input(ModelsInterface::PARAM_PAGE) === null) ? $this->defaultPage :
            $request->input(ModelsInterface::PARAM_PAGE);
        $limit = ($request->input(ModelsInterface::PARAM_LIMIT) === null) ? $this->defaultLimit :
            $request->input(ModelsInterface::PARAM_LIMIT);
        $sort = ($request->input(ModelsInterface::PARAM_SORT) === null) ? $this->defaultSort :
            $request->input(ModelsInterface::PARAM_SORT);
        $data = ($request->input(ModelsInterface::PARAM_DATA) === null) ? ModelsInterface::DEFAULT_DATA
            : Json::decode($request->input(ModelsInterface::PARAM_DATA));
        $orderBy = ($request->input(ModelsInterface::PARAM_ORDER_BY) === null) ? [RamlInterface::RAML_ID => $sort]
            : Json::decode($request->input(ModelsInterface::PARAM_ORDER_BY));
        $filter = ($request->input(ModelsInterface::PARAM_FILTER) === null) ? [] : Json::decode($request->input(ModelsInterface::PARAM_FILTER));
        $sqlOptions->setLimit($limit);
        $sqlOptions->setPage($page);
        $sqlOptions->setData($data);
        $sqlOptions->setOrderBy($orderBy);
        $sqlOptions->setFilter($filter);

        return $sqlOptions;
    }

    private function setConfigOptions()
    {
        $this->configOptions = new ConfigOptions();
        $this->configOptions->setJwtIsEnabled(Config::getJwtParam(ConfigInterface::ENABLED));
        $this->configOptions->setJwtTable(Config::getJwtParam(ModelsInterface::MIGRATION_TABLE));
        if($this->configOptions->getJwtIsEnabled() === true && $this->configOptions->getJwtTable() === MigrationsHelper::getTableName($this->entity))
        {// if jwt enabled=true and tables are equal
            $this->configOptions->setIsJwtAction(true);
        }
    }
}