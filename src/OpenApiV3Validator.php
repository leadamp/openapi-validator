<?php

namespace PaddleHq\OpenApiValidator;

use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use PaddleHq\OpenApiValidator\Exception\ContentTypeNotFoundException;
use PaddleHq\OpenApiValidator\Exception\InvalidRequestException;
use PaddleHq\OpenApiValidator\Exception\MethodNotFoundException;
use PaddleHq\OpenApiValidator\Exception\PathNotFoundException;
use PaddleHq\OpenApiValidator\Exception\InvalidResponseException;
use PaddleHq\OpenApiValidator\Exception\ResponseNotFoundException;
use JsonSchema\Validator as JsonSchemaValidator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OpenApiV3Validator implements OpenApiValidatorInterface
{
    /**
     * @var OpenApiV3ToJsonSchemaConverter
     */
    private $converter;

    /**
     * @var JsonSchemaValidator
     */
    private $jsonSchemaValidator;

    /**
     * @var string
     */
    private $currentPath;

    /**
     * @var string
     */
    private $currentMethod;

    /**
     * @var int
     */
    private $currentResponseStatusCode;

    /**
     * @var string
     */
    private $currentContentType;

    /**
     * @var SchemaStorage
     */
    private $schemaStorage;

    /**
     * @var string
     */
    private $openApiSchemaFileName;

    /**
     * @var bool
     */
    private $isResponse;

    /**
     * @param string                         $openApiSchemaFileName
     * @param OpenApiV3ToJsonSchemaConverter $converter
     * @param SchemaStorage                  $schemaStorage
     */
    public function __construct(
        string $openApiSchemaFileName,
        OpenApiV3ToJsonSchemaConverter $converter,
        SchemaStorage $schemaStorage
    ) {
        $this->converter = $converter;
        $this->openApiSchemaFileName = $openApiSchemaFileName;
        $this->schemaStorage = $schemaStorage;
        $this->setupSchema();
        $this->jsonSchemaValidator = new JsonSchemaValidator(new Factory($this->schemaStorage));
    }

    /**
     * Converts OpenApi v3 schema to json schema draft 4, adds it to storage.
     */
    private function setupSchema()
    {
        $openApiSchema = json_decode(file_get_contents($this->openApiSchemaFileName));
        $this->schemaStorage->addSchema($this->openApiSchemaFileName, $this->converter->convertDocument($openApiSchema));
    }

    /**
     * Validate a response against the OpenApi schema.
     *
     * {@inheritdoc}
     */
    public function validateResponse(
        ResponseInterface $response,
        string $pathName,
        string $method,
        int $responseCode,
        string $contentType = 'application/json'
    ): bool {
        $this->isResponse = true;

        if (!$this->emptyResponseExpected($responseCode)) {
            $responseSchemaPath = $this->getResponseSchemaPath($this->sanitizePathName($pathName), $method, $responseCode, $contentType);
            $responseJson = json_decode($response->getBody());
            $this->jsonSchemaValidator->validate($responseJson, (object) ['$ref' => $responseSchemaPath]);
        }

        if (!$this->jsonSchemaValidator->isValid()) {
            throw new InvalidResponseException($response, $this->schemaStorage->resolveRef($responseSchemaPath), $this->jsonSchemaValidator->getErrors());
        }

        return true;
    }

    /**
     * Validate a response against the OpenApi schema.
     *
     * {@inheritdoc}
     */
    public function validateRequest(
        RequestInterface $request,
        string $pathName,
        string $method,
        string $contentType = 'application/json'
    ): bool {
        $this->isResponse = false;

        $requestSchemaPath = $this->getRequestBodySchemaPath($this->sanitizePathName($pathName), $method, $contentType);
        $requestJson = json_decode($request->getBody());
        $this->jsonSchemaValidator->validate($requestJson, (object) ['$ref' => $requestSchemaPath]);

        if (!$this->jsonSchemaValidator->isValid()) {
            throw new InvalidRequestException($request, $this->schemaStorage->resolveRef($requestSchemaPath), $this->jsonSchemaValidator->getErrors());
        }

        return true;
    }

    /**
     * @param string $pathName
     * @param string $method
     * @param int    $responseCode
     * @param string $contentType
     *
     * @return string
     *
     * @throws ContentTypeNotFoundException
     * @throws MethodNotFoundException
     * @throws PathNotFoundException
     * @throws ResponseNotFoundException
     */
    private function getResponseSchemaPath(string $pathName, string $method, int $responseCode, string $contentType): string
    {
        $this->setSchemaPath($pathName)
            ->setPathMethod($method)
            ->setResponseStatusCode($responseCode)
            ->setContentType($contentType);

        return sprintf(
            '%s#paths/%s/%s/responses/%d/content/%s/schema',
            $this->openApiSchemaFileName,
            str_replace('/', '~1', $this->currentPath),
            $this->currentMethod,
            $this->currentResponseStatusCode,
            str_replace('/', '~1', $this->currentContentType)
        );
    }

    private function getRequestBodySchemaPath(
        string $pathName,
        string $method,
        string $contentType
    )
    {
        $this->setSchemaPath($pathName)
            ->setPathMethod($method)
            ->setContentType($contentType);

        return sprintf(
            '%s#paths/%s/%s/requestBody/content/%s/schema',
            $this->openApiSchemaFileName,
            str_replace('/', '~1', $this->currentPath),
            $this->currentMethod,
            str_replace('/', '~1', $this->currentContentType)
        );
    }

    private function sanitizePathName(string $pathName)
    {
        return preg_replace('/\?.*/', '', $pathName);
    }

    /**
     * @param string $pathName
     *
     * @return OpenApiV3Validator
     *
     * @throws PathNotFoundException
     */
    private function setSchemaPath(string $pathName): self
    {
        if (!property_exists(
            $this->schemaStorage
                ->getSchema($this->openApiSchemaFileName)
                ->paths,
            $pathName
        )) {
            throw new PathNotFoundException($pathName);
        }

        $this->currentPath = $pathName;

        return $this;
    }

    /**
     * @param string $method
     *
     * @return OpenApiV3Validator
     *
     * @throws MethodNotFoundException
     */
    private function setPathMethod(string $method): self
    {
        $method = strtolower($method);

        if (!property_exists(
            $this->schemaStorage
                ->getSchema($this->openApiSchemaFileName)
                ->paths
                ->{$this->currentPath},
            $method
        )) {
            throw new MethodNotFoundException($method, $this->currentPath);
        }

        $this->currentMethod = $method;

        return $this;
    }

    /**
     * @param int $responseCode
     *
     * @return OpenApiV3Validator
     *
     * @throws ResponseNotFoundException
     */
    private function setResponseStatusCode(int $responseCode): self
    {
        if (!property_exists(
            $this->schemaStorage
                ->getSchema($this->openApiSchemaFileName)
                ->paths
                ->{$this->currentPath}
                ->{$this->currentMethod}
                ->responses,
            $responseCode
        )) {
            throw new ResponseNotFoundException($responseCode, $this->currentMethod, $this->currentPath);
        }

        $this->currentResponseStatusCode = $responseCode;

        return $this;
    }

    /**
     * @param string $contentType
     *
     * @return OpenApiV3Validator
     *
     * @throws ContentTypeNotFoundException
     */
    private function setContentType(string $contentType): self
    {
        if ($this->contentTypeDoesNotExist($contentType)) {
            throw new ContentTypeNotFoundException(
                $contentType,
                $this->currentResponseStatusCode,
                $this->currentMethod,
                $this->currentPath
            );
        }

        $this->currentContentType = $contentType;

        return $this;
    }

    private function contentTypeDoesNotExist(string $contentType): bool
    {
        if ($this->isResponse) {
            return !property_exists(
                $this->schemaStorage
                    ->getSchema($this->openApiSchemaFileName)
                    ->paths
                    ->{$this->currentPath}
                    ->{$this->currentMethod}
                    ->responses
                    ->{$this->currentResponseStatusCode}
                    ->content,
                $contentType
            );
        }

        // is a request
        return !property_exists(
            $this->schemaStorage
                ->getSchema($this->openApiSchemaFileName)
                ->paths
                ->{$this->currentPath}
                ->{$this->currentMethod}
                ->requestBody
                ->content,
            $contentType
        );
    }

    /**
     * @param string $responseCode
     *
     * @return bool
     */
    private function emptyResponseExpected($responseCode): bool
    {
        return 204 === $responseCode;
    }
}
