<?php

namespace DreamFactory\Core\Resources\System;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\ServiceResponse;
use ServiceManager;

/**
 * Class Event
 *
 * @package DreamFactory\Core\Resources
 */
class Event extends BaseRestResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @const string A cached process-handling events list derived from API Docs
     */
    const EVENT_CACHE_KEY = 'events';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var array The process-handling event map
     */
    protected static $eventMap = false;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Internal building method builds all static services and some dynamic
     * services from file annotations, otherwise swagger info is loaded from
     * database or storage files for each service, if it exists.
     *
     */
    protected static function buildEventMaps()
    {
        \Log::info('Building event cache');

        //  Build event mapping from services in database

        //	Initialize the event map
        $eventMap = [];

        //	Spin through services and pull the events
        /** @var ServiceInterface[] $services */
        if (!empty($services = ServiceManager::getServices())) {
            foreach ($services as $apiName => $service) {
                if (!empty($map = $service->getEventMap())) {
                    $eventMap[$apiName] = $map;
                }
            }
        }

        static::$eventMap = $eventMap;

        \Log::info('Event cache build process complete');
    }

    /**
     * @param array $content
     * @param array $access
     *
     * @return array
     */
    protected static function parseSwaggerEvents(array $content, array $access = [])
    {
        $events = [];
        $eventCount = 0;

        foreach (array_get($content, 'paths', []) as $path => $api) {
            $apiEvents = [];
            $apiParameters = [];
            $pathParameters = [];

            $eventPath = str_replace('/', '.', trim($path, '/'));
            $resourcePath = ltrim(strstr(trim($path, '/'), '/'), '/');
            $replacePos = strpos($resourcePath, '{');

            foreach ($api as $ixOps => $operation) {
                if ('parameters' === $ixOps) {
                    $pathParameters = $operation;
                    continue;
                }

                $method = strtolower($ixOps);
                if (!isset($apiEvents[$method])) {
                    $apiEvents[$method][] = "$eventPath.$method";
                    $parameters = array_get($operation, 'parameters', []);
                    if (!empty($pathParameters)) {
                        $parameters = array_merge($pathParameters, $parameters);
                    }
                    foreach ($parameters as $parameter) {
                        $type = array_get($parameter, 'in', '');
                        if ('path' === $type) {
                            $name = array_get($parameter, 'name', '');
                            $options = array_get($parameter, 'enum', array_get($parameter, 'options'));
                            if (empty($options) && !empty($access) && (false !== $replacePos)) {
                                $checkFirstOption = strstr(substr($resourcePath, $replacePos + 1), '}', true);
                                if ($name !== $checkFirstOption) {
                                    continue;
                                }
                                $options = [];
                                // try to match any access path
                                foreach ($access as $accessPath) {
                                    $accessPath = rtrim($accessPath, '/*');
                                    if (!empty($accessPath) && (strlen($accessPath) > $replacePos)) {
                                        if (0 === substr_compare($accessPath, $resourcePath, 0, $replacePos)) {
                                            $option = substr($accessPath, $replacePos);
                                            if (false !== strpos($option, '/')) {
                                                $option = strstr($option, '/', true);
                                            }
                                            $options[] = $option;
                                        }
                                    }
                                }
                            }
                            if (!empty($options)) {
                                $apiParameters[$name] = array_values(array_unique($options));
                            }
                        }
                    }
                }

                unset($operation);
            }

            $events[$eventPath]['verb'] = $apiEvents;
            $apiParameters = (empty($apiParameters)) ? null : $apiParameters;
            $events[$eventPath]['parameter'] = $apiParameters;

            unset($apiEvents, $apiParameters, $api);
        }

        \Log::debug('  * Discovered ' . $eventCount . ' event(s).');

        return $events;
    }

    /**
     * Retrieves the cached event map or triggers a rebuild
     *
     * @param bool $refresh
     *
     * @return array
     */
    public static function getAllEventMaps($refresh = false)
    {
        if (!empty(static::$eventMap)) {
            return static::$eventMap;
        }

        static::$eventMap = ($refresh ? [] : \Cache::get(static::EVENT_CACHE_KEY));

        //	If we still have no event map, build it.
        if (empty(static::$eventMap)) {
            static::buildEventMaps();
            //	Write event cache file
            \Cache::forever(static::EVENT_CACHE_KEY, static::$eventMap);
        }

        return static::$eventMap;
    }

    /**
     * Clears the cache produced by the swagger annotations
     */
    public static function clearCache()
    {
        static::$eventMap = [];
        \Cache::forget(static::EVENT_CACHE_KEY);
    }

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Handles GET action
     *
     * @return array|ServiceResponse
     * @throws NotFoundException
     */
    protected function handleGET()
    {
        $refresh = $this->request->getParameterAsBool('refresh');
        $scriptable = $this->request->getParameterAsBool('scriptable');
        $service = $this->request->getParameter('service');
        $results = $this->getAllEventMaps($refresh);
        $allEvents = [];
        foreach ($results as $serviceKey => $paths) {
            if (!empty($service) && (0 !== strcasecmp($service, $serviceKey))) {
                unset($results[$serviceKey]);
            } else {
                foreach ($paths as $path => $operations) {
                    if (empty($type = array_get($operations, 'type'))) {
                        $type = 'service';
                        $results[$serviceKey][$path]['type'] = $type;
                    }
                    if (!empty($endpoints = (array)array_get($operations, 'endpoints', $path))) {
                        if ($scriptable) {
                            $temp = [];
                            foreach ($endpoints as $endpoint) {
                                $temp[] = $endpoint;
                                switch ($type) {
                                    case 'api':
                                        // add pre_process, post_process
                                        $temp[] = "$endpoint.pre_process";
                                        $temp[] = "$endpoint.post_process";
                                        break;
                                    case 'service':
                                        break;
                                }
                                // add queued
                                $temp[] = "$endpoint.queued";
                            }
                            $endpoints = $temp;
                            $results[$serviceKey][$path]['endpoints'] = $temp;
                        }
                        $allEvents = array_merge($allEvents, $endpoints);
                    }
                }
            }
        }

        if (!$this->request->getParameterAsBool(ApiOptions::AS_LIST)) {
            return $results;
        }

        return ResourcesWrapper::cleanResources(array_values(array_unique($allEvents)));
    }

    public static function getApiDocInfo($service, array $resource = [])
    {
        $serviceName = strtolower($service);
        $capitalized = camelize($service);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $resourceName = strtolower(array_get($resource, 'name', $class));
        $path = '/' . $serviceName . '/' . $resourceName;

        $paths = [
            $path => [
                'get' => [
                    'tags'        => [$serviceName],
                    'summary'     => 'get' . $capitalized . 'EventList() - Retrieve list of events.',
                    'operationId' => 'get' . $capitalized . 'EventList',
                    'description' => 'A list of event names are returned. The list can be limited by service.',
                    'consumes'    => ['application/json', 'application/xml', 'text/csv'],
                    'produces'    => ['application/json', 'application/xml', 'text/csv'],
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::AS_LIST),
                        [
                            'name'        => 'service',
                            'description' => 'Get the events for only this service.',
                            'type'        => 'string',
                            'in'          => 'query',
                            'required'    => false,
                        ],
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Resource List',
                            'schema'      => ['$ref' => '#/definitions/ResourceList']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                ],
            ],
        ];

        return ['paths' => $paths, 'definitions' => []];
    }
}