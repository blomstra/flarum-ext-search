<?php

/*
 * This file is part of blomstra/search.
 *
 * Copyright (c) 2022 Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 *
 */

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Commands\BuildCommand;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class IndexController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var \Flarum\User\User */
        $actor = $request->getAttribute('actor');
        $actor->assertAdmin();

        /** @var BuildCommand $command */
        $command = resolve(BuildCommand::class);

        $command->run(
            new ArrayInput([]),
            new ConsoleOutput()
        );

        return new EmptyResponse();
    }
}
