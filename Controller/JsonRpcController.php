<?php

namespace Wa72\JsonRpcBundle\Controller;

use Doctrine\Common\Annotations\DocParser;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zend\Code\Reflection\DocBlock\Tag\ParamTag;
use Zend\Code\Reflection\DocBlock\Tag\ReturnTag;

/**
 * Controller for executing JSON-RPC 2.0 requests
 * see http://www.jsonrpc.org/specification
 *
 * Only functions of services registered in the DI container may be called.
 *
 * The constructor expects the DI container and a configuration array where
 * the mapping from the jsonrpc method names to service methods is defined:
 *
 * $config = array(
 *   'functions' => array(
 *      'myfunction1' => array(
 *          'service' => 'mybundle.servicename',
 *          'method' => 'methodofservice'
 *      ),
 *      'anotherfunction' => array(
 *          'service' => 'mybundle.foo',
 *          'method' => 'bar'
 *      )
 *   )
 * );
 *
 * A method name "myfunction1" in the RPC request will then call
 * $this->container->get('mybundle.servicename')->methodofservice()
 *
 * If you want to add a service completely so that all public methods of
 * this service may be called, use the addService($servicename) method.
 * Methods of the services added this way can be called remotely using
 * "servicename:method" as RPC method name.
 *
 * @license MIT
 * @author Christoph Singer
 *
 */
class JsonRpcController extends ContainerAware
{
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;

    /**
     * Functions that are allowed to be called
     *
     * @var array $functions
     */
    private $functions = array();

    /**
     * Configuration for serializer
     * @var array
     */
    private $serializer = array();

    /**
     * Array of names of fully exposed services (all methods of this services are allowed to be called)
     *
     * @var array $services
     */
    private $services = array();

    /**
     * @var \JMS\Serializer\SerializationContext
     */
    private $serializationContext;

    /**
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     * @param array $config Associative array for configuration, expects at least a key "functions"
     * @throws \InvalidArgumentException
     */
    public function __construct($container, $config)
    {
        if (isset($config['functions'])) {
            if (!is_array($config['functions'])) throw new \InvalidArgumentException('Configuration parameter "functions" must be array');
            $this->functions = $config['functions'];
        }
        if (isset($config['serializer'])) {
            $this->serializer = $config['serializer'];
        }
        $this->setContainer($container);
    }

    /**
     * @param Request $inRequest
     * @return Response
     */
    public function execute(Request $inRequest)
    {
        $json = $inRequest->getContent();
        $requestAsObject = json_decode($json);

        if (is_array($requestAsObject)) {
            // Batch request

            $responses = [];

            foreach ($requestAsObject as $singleRequest) {
                $singleResponse = $this->_execute(json_encode($singleRequest));

                // Eliminate notification response
                if ($singleResponse->getContent()) {
                    $responses[] = json_decode($singleResponse->getContent());
                }
            }

            return new Response(json_encode($responses), 200, ['Content-Type' => 'application/json']);
        } else {
            return $this->_execute($json);
        }
    }

    /**
     * @param string $json
     * @return Response
     */
    private function _execute($json)
    {
        $requestAsArray = json_decode($json, true);
        $requestId = (isset($requestAsArray['id']) ? $requestAsArray['id'] : null);

        if ($requestAsArray === null) {
            return $this->getErrorResponse(self::PARSE_ERROR, null);
        } elseif (!(isset($requestAsArray['jsonrpc']) && isset($requestAsArray['method']) && $requestAsArray['jsonrpc'] == '2.0')) {
            return $this->getErrorResponse(self::INVALID_REQUEST, $requestId);
        }

        if (in_array($requestAsArray['method'], array_keys($this->functions))) {
            $servicename = $this->functions[$requestAsArray['method']]['service'];
            $method = $this->functions[$requestAsArray['method']]['method'];
        } else {
            if (count($this->services) && strpos($requestAsArray['method'], ':') > 0) {
                list($servicename, $method) = explode(':', $requestAsArray['method']);
                if (!in_array($servicename, $this->services)) {
                    return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
                }
            } else {
                return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
            }
        }
        try {
            $service = $this->container->get($servicename);
        } catch (ServiceNotFoundException $e) {
            return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
        }
        $params = (isset($requestAsArray['params']) ? $requestAsArray['params'] : array());

        if (is_callable(array($service, $method))) {
            $r = new \ReflectionMethod($service, $method);
            $rps = $r->getParameters();

            if (is_array($params)) {
                if (!(count($params) >= $r->getNumberOfRequiredParameters()
                    && count($params) <= $r->getNumberOfParameters())
                ) {
                    return $this->getErrorResponse(self::INVALID_PARAMS, $requestId,
                        sprintf('Number of given parameters (%d) does not match the number of expected parameters (%d required, %d total)',
                            count($params), $r->getNumberOfRequiredParameters(), $r->getNumberOfParameters()));
                }

            }
            if ($this->isAssoc($params)) {
                $newparams = array();
                foreach ($rps as $i => $rp) {
                    /* @var \ReflectionParameter $rp */
                    $name = $rp->name;
                    if (!isset($params[$rp->name]) && !$rp->isOptional()) {
                        return $this->getErrorResponse(self::INVALID_PARAMS, $requestId,
                            sprintf('Parameter %s is missing', $name));
                    }
                    if (isset($params[$rp->name])) {
                        $newparams[] = $params[$rp->name];
                    } else {
                        // Is optional but not set, do not set then.
//                        $newparams[] = $rp->isArray() ? [] : null;
                    }
                }
                $params = $newparams;
            }

            // correctly deserialize object parameters
            foreach ($params as $index => $param) {
                // if the json_decode'd param value is an array but an object is expected as method parameter,
                // re-encode the array value to json and correctly decode it using jsm_serializer
                if (is_array($param) && !$rps[$index]->isArray() && $rps[$index]->getClass() != null) {
                    $class = $rps[$index]->getClass()->getName();
                    $param = json_encode($param);
                    $params[$index] = $this->container->get('jms_serializer')->deserialize($param, $class, 'json');
                }
            }

            try {
                $result = call_user_func_array(array($service, $method), $params);
            } catch (\Exception $e) {
                return $this->getErrorResponse(self::INTERNAL_ERROR, $requestId, $this->convertExceptionToErrorData($e));
            }

            $response = array('jsonrpc' => '2.0');
            $response['result'] = $result;
            $response['id'] = $requestId;

            if ($this->container->has($this->serializer['service'])) {
                $functionConfig = (
                isset($this->functions[$requestAsArray['method']])
                    ? $this->functions[$requestAsArray['method']]
                    : array()
                );
                $serializationContext = $this->getSerializationContext($functionConfig);
                $response = $this->container->get($this->serializer['service'])->serialize($response, 'json', $serializationContext);
            } else {
                $response = json_encode($response);
            }

            if ($requestId) {
                return new Response($response, 200, ['Content-Type' => 'application/json']);
            } else {
                return new Response();
            }
        } else {
            return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
        }

    }

    public function discover()
    {
        $annotationReader = $this->container->get('annotation_reader');
        /* @var $annotationReader \Doctrine\Common\Annotations\AnnotationReader */

        foreach ($this->services as $serviceName) {
            $service = $this->container->get($serviceName);
            $rService = new \Zend\Code\Reflection\ClassReflection($service);

            $jsonrpcMethods[$serviceName]['service'] = $serviceName;
            $jsonrpcMethods[$serviceName]['description'] = !$rService->getDocBlock() ?: $rService->getDocBlock()->getShortDescription();

            foreach ($rService->getMethods(\ReflectionMethod::IS_PUBLIC) as $rMethod) {

                if (strpos($rMethod->getName(), '__') !== 0) {
                    $currentMethod = [];

                    $_tagsParam = !$rMethod->getDocBlock() ? [] : $rMethod->getDocBlock()->getTags('param');
                    $_tagsReturn = !$rMethod->getDocBlock() ? null : $rMethod->getDocBlock()->getTag('return');
                    /* @var $_tagsParam ParamTag[] */
                    /* @var $_tagsReturn ReturnTag */

                    $currentMethod['method'] = "{$serviceName}:" . $rMethod->getName();
                    $currentMethod['description'] = !$rMethod->getDocBlock() ?: $rMethod->getDocBlock()->getShortDescription();
                    $currentMethod['parameters'] = [];
                    if (!empty($_tagsParam)) {
                        foreach ($_tagsParam as $_tagParam) {
                            if (strpos($_tagParam->getVariableName(), '$') === 0) {
                                $_name = substr($_tagParam->getVariableName(), 1);
                            } else {
                                $_name = $_tagParam->getVariableName();
                            }
                            $currentMethod['parameters'][$_name] = [
                                'description' => $_tagParam->getDescription(),
                                'types' => count($_tagParam->getTypes()) == 1 ? $_tagParam->getTypes()[0] : $_tagParam->getTypes(),
                            ];
                        }
                    }

                    foreach ($rMethod->getParameters() as $rParameter) {
                        if (!isset($currentMethod['parameters'][$rParameter->getName()])) {
                            $currentMethod['parameters'][$rParameter->getName()] = [];
                        }
                        $currentMethod['parameters'][$rParameter->getName()] = array_merge(
                            $currentMethod['parameters'][$rParameter->getName()],
                            [
                                'nullable' => $rParameter->allowsNull(),
                                'array' => $rParameter->isArray(),
                                'optional' => $rParameter->isOptional(),
                            ]
                        );
                        if ($rParameter->isDefaultValueAvailable()) {
                            $currentMethod['parameters'][$rParameter->getName()]['default'] = $rParameter->getDefaultValue();
                        }
                    }

                    if ($_tagsReturn) {
                        $currentMethod['return'] = [
                            'description' => $_tagsReturn->getDescription(),
                            'types' => count($_tagsReturn->getTypes()) == 1 ? $_tagsReturn->getTypes()[0] : $_tagsReturn->getTypes(),
                        ];
                    }

                    $jsonrpcMethods[$serviceName]['methods'][$rMethod->getName()] = $currentMethod;
                }
            }
        }

        return new Response(json_encode($jsonrpcMethods), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Add a new function that can be called by RPC
     *
     * @param string $alias The function name used in the RPC call
     * @param string $service The service name of the method to call
     * @param string $method The method of $service
     * @param bool $overwrite Whether to overwrite an existing function
     * @throws \InvalidArgumentException
     */
    public function addMethod($alias, $service, $method, $overwrite = false)
    {
        if (!isset($this->functions)) $this->functions = array();
        if (isset($this->functions[$alias]) && !$overwrite) {
            throw new \InvalidArgumentException('JsonRpcController: The function "' . $alias . '" already exists.');
        }
        $this->functions[$alias] = array(
            'service' => $service,
            'method' => $method
        );
    }

    /**
     * Add a new service that is fully exposed by json-rpc
     *
     * @param string $service The id of a service
     */
    public function addService($service)
    {
        $this->services[] = $service;
    }

    /**
     * Remove a method definition
     *
     * @param string $alias
     */
    public function removeMethod($alias)
    {
        if (isset($this->functions[$alias])) {
            unset($this->functions[$alias]);
        }
    }

    protected function convertExceptionToErrorData(\Exception $e)
    {
        return $e->getMessage();
    }

    protected function getError($code)
    {
        $message = '';
        switch ($code) {
            case self::PARSE_ERROR:
                $message = 'Parse error';
                break;
            case self::INVALID_REQUEST:
                $message = 'Invalid request';
                break;
            case self::METHOD_NOT_FOUND:
                $message = 'Method not found';
                break;
            case self::INVALID_PARAMS:
                $message = 'Invalid params';
                break;
            case self::INTERNAL_ERROR:
                $message = 'Internal error';
                break;
        }

        return array('code' => $code, 'message' => $message);
    }

    protected function getErrorResponse($code, $id, $data = null)
    {
        $response = array('jsonrpc' => '2.0');
        $response['error'] = $this->getError($code);

        if ($data != null) {
            $response['error']['data'] = $data;
        }

        $response['id'] = $id;

        if ($id) {
            return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } else {
            return new Response();
        }
    }

    /**
     * Set SerializationContext for using with jms_serializer
     *
     * @param \JMS\Serializer\SerializationContext $context
     */
    public function setSerializationContext($context)
    {
        $this->serializationContext = $context;
    }

    /**
     * Get SerializationContext or creates one if jms_serialization_context option is set
     *
     * @param array $functionConfig
     * @return \JMS\Serializer\SerializationContext
     */
    protected function getSerializationContext(array $functionConfig)
    {
        if (isset($functionConfig['jms_serialization_context'])) {
            $serializationContext = \JMS\Serializer\SerializationContext::create();

            if (isset($functionConfig['jms_serialization_context']['groups'])) {
                $serializationContext->setGroups($functionConfig['jms_serialization_context']['groups']);
            }

            if (isset($functionConfig['jms_serialization_context']['version'])) {
                $serializationContext->setVersion($functionConfig['jms_serialization_context']['version']);
            }

            if (isset($functionConfig['jms_serialization_context']['max_depth_checks'])) {
                $serializationContext->enableMaxDepthChecks($functionConfig['jms_serialization_context']['max_depth_checks']);
            }
        } else {
            $serializationContext = $this->serializationContext;
        }

        if ($this->serializer['serialize_null']) {
            if ($serializationContext === null) {
                $serializationContext = \JMS\Serializer\SerializationContext::create();
            }
            $serializationContext->setSerializeNull(true);
        }

        return $serializationContext;
    }

    /**
     * Finds whether a variable is an associative array
     *
     * @param $var
     * @return bool
     */
    protected function isAssoc($var)
    {
        return array_keys($var) !== range(0, count($var) - 1);
    }
}
