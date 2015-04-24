<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace DreamFactory\Rave\Testing;

use DreamFactory\Rave\Components\InternalServiceRequest;
use DreamFactory\Rave\Contracts\ServiceRequestInterface;
use DreamFactory\Rave\Enums\ServiceRequestorTypes;
use \Exception;

/**
 * Class TestServiceRequest
 *
 */
class TestServiceRequest implements ServiceRequestInterface
{
    use InternalServiceRequest;

    /**
     * @var int, see ServiceRequestorTypes
     */
    protected $requestorType = ServiceRequestorTypes::API;

    /**
     * {@inheritdoc}
     */
    public function getRequestorType()
    {
        return $this->requestorType;
    }

    /**
     * @param integer $type, see ServiceRequestorTypes
     *
     * @throws Exception
     */
    public function setRequestorType( $type )
    {
        if ( ServiceRequestorTypes::contains( $type ) )
        {
            $this->requestorType = $type;
        }

        throw new Exception( 'Invalid service requestor type provided.');
    }
}