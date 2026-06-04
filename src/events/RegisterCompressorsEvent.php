<?php

namespace bymayo\squash\events;

use bymayo\squash\compressors\CompressorInterface;
use yii\base\Event;

/**
 * Fired by the Compressors service so other plugins/modules can register their
 * own compression drivers.
 *
 * ```php
 * Event::on(
 *     Compressors::class,
 *     Compressors::EVENT_REGISTER_COMPRESSORS,
 *     function (RegisterCompressorsEvent $e) {
 *         $e->compressors['my-driver'] = new MyCompressor();
 *     }
 * );
 * ```
 */
class RegisterCompressorsEvent extends Event
{
    /**
     * Drivers keyed by handle. Add to this array to register a driver.
     * @var array<string, CompressorInterface>
     */
    public array $compressors = [];
}
