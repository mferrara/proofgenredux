<?php
require dirname(__DIR__, 3).'/vendor/autoload.php';
// No application bootstrap, .env, database, queues or real photo files.
$root = sys_get_temp_dir().'/proofgen-image-review-probe-'.getmypid();
mkdir($root.'/storage/watermarks', 0755, true);
$app = new Illuminate\Foundation\Application($root);
$app->useStoragePath($root.'/storage');
$app->instance('config', new Illuminate\Config\Repository([
 'filesystems'=>['disks'=>['fullsize'=>['driver'=>'local','root'=>$root,'throw'=>true]]],
 'proofgen'=>['fullsize_home_dir'=>$root,'image_enhancement_enabled'=>true,'enhancement_apply_to_proofs'=>true,'watermark_proofs'=>false,
 'thumbnails'=>['small'=>['suffix'=>'_thm','width'=>60,'height'=>40,'quality'=>90],'large'=>['suffix'=>'_std','width'=>120,'height'=>80,'quality'=>90]],
 'highres_images'=>['suffix'=>'_highres','width'=>120,'height'=>80,'quality'=>90]]
]));
$app->instance('filesystem', new Illuminate\Filesystem\FilesystemManager($app));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$manager = new Intervention\Image\ImageManager(Intervention\Image\Drivers\Gd\Driver::class);
$original=imagecreatetruecolor(120,80); imagefill($original,0,0,imagecolorallocate($original,0,0,255)); imagejpeg($original,$root.'/source.jpg');
$marker=imagecreatetruecolor(120,80); imagefill($marker,0,0,imagecolorallocate($marker,255,0,0)); imagejpeg($marker,$root.'/enhanced.jpg');
$app->instance(App\Services\CoreImageDaemonService::class, new class($root.'/enhanced.jpg') extends App\Services\CoreImageDaemonService {
 public function __construct(private string $marker) {}
 public function isCoreImageAvailable(): bool { return true; }
 public function enhance(string $imagePath,string $method,array $parameters=[]): Intervention\Image\Image { return (new Intervention\Image\ImageManager(Intervention\Image\Drivers\Gd\Driver::class))->decodePath($this->marker); }
});
App\Proofgen\Image::createThumbnails('source.jpg','proofs');
$pixels=[];
foreach(['thm','std'] as $suffix) { $gd=imagecreatefromjpeg($root.'/proofs/source_'.$suffix.'.jpg'); $v=imagecolorat($gd,2,2); $pixels[$suffix]=[($v>>16)&255,($v>>8)&255,$v&255]; }
echo json_encode(['enhanced_marker'=>'red','original'=>'blue','output_pixels'=>$pixels])."\n";
config(['proofgen.image_enhancement_enabled'=>false]);
set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
try { App\Proofgen\Image::createHighresImage('source.jpg','highres'); } catch(Throwable $e) { echo json_encode(['missing_watermark_exception'=>get_class($e),'output_exists'=>is_file($root.'/highres/source_highres.jpg')])."\n"; }
restore_error_handler();
echo 'artifacts='.$root."\n";
$app->instance('image',$manager);
try { Intervention\Image\Laravel\Facades\Image::read(file_get_contents($root.'/source.jpg')); }
catch(Throwable $e) { echo json_encode(['thumbnail_view_decode'=>get_class($e),'message'=>$e->getMessage()])."\n"; }
$disk=Illuminate\Support\Facades\Storage::disk('fullsize');
$disk->put('SHOW/1/originals/100.jpg','SOURCE');
$disk->put('SHOW/2/originals/100.jpg','ORPHAN_DESTINATION');
$photo=(new App\Models\Photo)->setRawAttributes(['id'=>'SHOW_1_100','show_class_id'=>'SHOW_1','proof_number'=>'100','file_type'=>'jpg']);
$target=(new App\Models\ShowClass)->setRawAttributes(['id'=>'SHOW_2','show_id'=>'SHOW','name'=>'2']);
$move=new App\Services\PhotoMoveService(new App\Services\PhotoArchiveService, new App\Services\PathResolver);
$method=new ReflectionMethod($move,'movePhotoFiles');
$method->invoke($move,$photo,$target);
echo json_encode(['destination_after_move'=>$disk->get('SHOW/2/originals/100.jpg'),'source_exists'=>$disk->exists('SHOW/1/originals/100.jpg')])."\n";
$disk->put('SHOW/1/_import_conflicts/conflict.jpg','IMAGE');
$disk->put('SHOW/1/_import_conflicts/conflict.jpg.json','{}');
$show=(new App\Models\Show)->setRawAttributes(['id'=>'SHOW','name'=>'SHOW']);
echo json_encode(['pending_show_import'=>array_values(array_map(fn($f)=>$f->path(),$show->getImagesPendingImport()))])."\n";
