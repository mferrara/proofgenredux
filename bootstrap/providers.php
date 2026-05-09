<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConfigurationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\PathServiceProvider;
use App\Providers\SampleImagesServiceProvider;

return [
    AppServiceProvider::class,
    ConfigurationServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    JetstreamServiceProvider::class,
    PathServiceProvider::class,
    SampleImagesServiceProvider::class,
];
