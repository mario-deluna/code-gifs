<?php

use App\Application;
use VISU\Quickstart;
use VISU\Quickstart\QuickstartOptions;

$container = require __DIR__ . '/../bootstrap.php';

$videoWidth = 1280;
$videoHeight = 720;
$videoFramerate = 60;
$outputVideo = 'output.mp4';

$quickstart = new Quickstart(function(QuickstartOptions $options) use ($container, $videoWidth, $videoHeight)
{
    $options->appClass = \App\Application::class;
    $options->container = $container;
    $options->windowTitle = $container->getParameter('project.name'); // defined in: /app.ctn
    $options->windowVsync = false;
    $options->windowHeadless = true;

    $options->windowWidth = $videoWidth;
    $options->windowHeight = $videoHeight;
});

/** @var Application */
$app = $quickstart->app();
$app->ready();

// actually adjust to the size of the code
$frame = $app->getFrameForText();
$frame->x = $frame->x + $app->padding + 32 * 2;
$frame->y = $frame->y + $app->padding + 62 * 2;

// round the resolution to the nearest 16
$frame->x = round($frame->x / 16) * 16;
$frame->y = round($frame->y / 16) * 16;

$app->window->setSize($frame->x, $frame->y);
$videoWidth = $frame->x;
$videoHeight = $frame->y;

echo "Rendering video at: {$videoWidth}x{$videoHeight}\n";

// delete previous output video
if (file_exists($outputVideo)) {
    unlink($outputVideo);
}

$command = "ffmpeg -f rawvideo -vcodec rawvideo -pix_fmt rgb24 -color_trc linear -s {$videoWidth}x{$videoHeight} -r {$videoFramerate} -i - -c:v libx264 -pix_fmt yuv422p -vf vflip " . $outputVideo;

$process = proc_open($command, [
    0 => ["pipe", "r"], // STDIN
    1 => ["file", VISU_PATH_CACHE . "/ffmpeg-output.txt", "w"], // STDOUT
    2 => ["file", VISU_PATH_CACHE . "/ffmpeg-error.log", "a"]  // STDERR
], $pipes);


if (!is_resource($process)) {
    throw new \RuntimeException("Failed to open ffmpeg process, are you sure it's installed?");
}

$pixelBuffer = new \GL\Buffer\UByteBuffer();
$numberOfFrames = 1600;

for($i=0; $i<$numberOfFrames; $i++) {

    echo "Frame: $i of $numberOfFrames (" . floor($i / $numberOfFrames * 100) . "%)\n";

    // tick and render the app
    $quickstart->app()->update();
    $quickstart->app()->render(0.0);

    // fetch the quickstart render target texture
    $texture = $quickstart
        ->app()
        ->renderResources
        ->findTextureByName('quickstartTarget.attachment.color_quickstartColor');

    // bind the texture and fetch the pixel data
    $texture->bind();
    glGetTexImage(GL_TEXTURE_2D, 0, GL_RGB, GL_UNSIGNED_BYTE, $pixelBuffer);

    // dump the pixel data to the ffmpeg process (RGBRGBRGB...)
    fwrite($pipes[0], $pixelBuffer->dump());
}

// close the pipe & process
fclose($pipes[0]);
proc_close($process);

// and finally generate a gif from the video
$outputGif = dirname($outputVideo) . '/output.gif';
$paletteFile = dirname($outputVideo) . '/palette.png';
unlink($outputGif);
unlink($paletteFile);

$commandGif = "ffmpeg -i " . escapeshellarg($outputVideo) . " -vf fps={$videoFramerate},scale={$videoWidth}:-1:flags=lanczos,palettegen " . escapeshellarg($paletteFile);
passthru($commandGif);

$commandGif = "ffmpeg -i " . escapeshellarg($outputVideo) . " -i " . escapeshellarg($paletteFile) . " -filter_complex \"fps=30,scale={$videoWidth}:-1:flags=lanczos[x];[x][1:v]paletteuse\" " . escapeshellarg($outputGif);
passthru($commandGif);