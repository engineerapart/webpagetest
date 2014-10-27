<?php
chdir('..');
require_once('common_lib.inc');
require_once('video/visualProgress.inc.php');
ignore_user_abort(true);
set_time_limit(600);

// Globals used throughout the video render
$width = 800;
$height = 600;
$padding = 2;
$minThumbnailSize = 100;
$black = null;
$textColor = null;
$image_bytes = null;
$font = __DIR__ . '/font/sourcesanspro-semibold.ttf';
$labelHeight = 40;
$timeHeight = 80;

// Load the information about the video that needs rendering
if (isset($_REQUEST['id'])) {
  $videoId = trim($_REQUEST['id']);
  $videoPath = './' . GetVideoPath($_REQUEST['id']);
  if (!is_file("$videoPath/video.ini")) {
    $optionsFile = "$videoPath/testinfo.json";
    if (gz_is_file($optionsFile)) {
      $tests = json_decode(gz_file_get_contents($optionsFile), true);
      if (isset($tests) && !is_array($tests))
        unset($tests);
    }
  }
}

// Render the video
if (isset($tests) && count($tests)) {
//  $lock = Lock("video-$videoId", false, 600);
//  if ($lock) {
    RenderVideo($tests);
//    $ini .= 'completed=' . gmdate('c') . "\r\n";
//    file_put_contents("$videoPath/video.ini", $ini);
//    Unlock($lock);
//  }
}

function RenderVideo(&$tests) {
  global $width, $height;
  
  // Figure out the end time of the video and
  // make sure all of the tests are restored.
  $videoEnd = 0;
  foreach($tests as &$test) {
    if (isset($test['id']))
      RestoreTest($test['id']);
    if (isset($test['end']) && is_numeric($test['end']) && $test['end'] > $videoEnd)
      $videoEnd = $test['end'];
    if (isset($test['path']) &&
        isset($test['run']) &&
        isset($test['cached'])) {
      $progress = GetVisualProgress("./{$test['path']}", $test['run'], $test['cached']);
      if (isset($progress) && is_array($progress) && isset($progress['frames'])) {
        $test['frames'] = $progress['frames'];
        if (count($test['frames'])) {
          $frame = current($test['frames']);
          $dim = getimagesize("./{$frame['path']}");
          $test['aspect'] = $dim[0] / $dim[1];
        }
      }
    }
  }
  
  if ($videoEnd > 0) {
    $frameCount = ceil($videoEnd * 60 / 1000);  // 60fps
    CalculateVideoDimensions($tests);
    $im = imagecreatetruecolor($width, $height);
    if ($im !== false) {
      RenderFrames($tests, $frameCount, $im);
      imagedestroy($im);
    }
  }
}

/**
* Figure out the dimensions of the resulting video
* 
*/
function CalculateVideoDimensions(&$tests) {
  global $width, $height, $minThumbnailSize, $padding, $labelHeight, $timeHeight;
  
  $rows = max(floor(sqrt(count($tests))), 1);
  $columns = max(ceil(count($tests) / $rows), 1);
  
  $cellWidth = max(floor($width / $columns), $minThumbnailSize + $padding);
  $cellHeight = max(floor($height - (($labelHeight + $timeHeight) * $rows) / $rows), $minThumbnailSize + $padding);
  
  $videoWidth = ($cellWidth * $columns) + $padding;
  $width = floor(($videoWidth + 7) / 8) * 8;  // Multiple of 8
  
  // Tighten the row sizes to fit each video (keep columns fixed for labeling)
  $row_h = array();
  foreach ($tests as $position => &$test) {
    $row = floor($position / $columns);
    $column = $position % $columns;
    if (isset($row_h[$row]) && $row_h[$row] > 0)
      $row_h[$row] = min($row_h[$row], $test['aspect']);
    else
      $row_h[$row] = $test['aspect'];
  }
  $height = 0;
  foreach ($row_h as $row => $aspect) {
    if ($aspect > 0)
      $row_h[$row] = min($cellHeight, ceil($cellWidth / $aspect));
    else
      $row_h[$row] = $cellHeight;
    $height += $row_h[$row];
  }
  $videoHeight = $height + $padding + (($labelHeight + $timeHeight) * $rows);
  $height = floor(($videoHeight + 7) / 8) * 8;  // Multiple of 8

  // figure out the left and right margins
  $left = floor(($width - $videoWidth) / 2);
  $top = floor(($height - $videoHeight) / 2);

  // Figure out the placement of each video  
  $y = $top + $labelHeight;
  foreach ($tests as $position => &$test) {
    $row = floor($position / $columns);
    $column = $position % $columns;
    if ($column == 0 && $row > 0)
      $y += $row_h[$row - 1] + $timeHeight + $labelHeight;
    $test['x'] = $left + ($column * $cellWidth) + $padding;
    $test['y'] = $y + $padding;
    $test['width'] = $cellWidth - $padding;
    $test['height'] = $row_h[$row] - $padding;
  }
}

/**
* Render the actual video frames
* 
* @param mixed $tests
* @param mixed $frameCount
* @param mixed $im
*/
function RenderFrames(&$tests, $frameCount, $im) {
  global $width, $height, $black, $videoPath, $image_bytes, $textColor;
  
  // prepare the image (black background)
  $black = imagecolorallocate($im, 0, 0, 0);
  $textColor = imagecolorallocate($im, 255, 255, 255);
  imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $black);
  $firstImage = true;
  
  // set up ffmpeg
  $descriptors = array(0 => array("pipe", "r"));
  $videoFile = realpath($videoPath) . '/video.mp4';
  if (is_file($videoFile))
    unlink($videoFile);
  $command = "ffmpeg -f image2pipe -r 60 -vcodec png -i - ".
                  "-vcodec libx264 -r 60 -crf 18 -g 30 ".
                  "-y \"$videoFile\"";
  $ffmpeg = proc_open($command, $descriptors, $pipes);
  if (is_resource($ffmpeg)){
    for ($frame = 0; $frame < $frameCount; $frame++) {
      RenderFrame($tests, $frame, $im);
      if (isset($image_bytes)) {
        fwrite($pipes[0], $image_bytes);
        if ($firstImage) {
          file_put_contents("$videoPath/video.png", $image_bytes);
          $firstImage = false;
        }
      }
    }
    fclose($pipes[0]);
    proc_close($ffmpeg);
  }
}

/**
* Render an individual frame
* 
* @param mixed $tests
* @param mixed $frame
* @param mixed $im
*/
function RenderFrame(&$tests, $frame, $im) {
  global $videoPath, $image_bytes;
  $updated = false;
  $frameTime = ceil($frame * 1000 / 60);
  foreach ($tests as &$test) {
    if (DrawTest($test, $frameTime, $im))
      $updated = true;
  }
  if ($updated) {
    $image_bytes = null;
    ob_start();
    imagepng($im);
    $image_bytes = ob_get_contents();
    ob_end_clean();
  }
}

/**
* Draw the appropriate thumbnail for the given frame
* 
* @param mixed $test
* @param mixed $frameTime
* @param mixed $im
*/
function DrawTest(&$test, $frameTime, $im) {
  global $black;
  $updated = false;

  // find the closest video frame <= the target time
  $frame_ms = null;
  foreach ($test['frames'] as $ms => $frame) {
    if ($ms <= $frameTime && (!isset($frame_ms) || $ms > $frame_ms))
      $frame_ms = $ms;
  }
  $path = null;
  if (isset($frame_ms))
    $path = $test['frames'][$frame_ms]['path'];
    
  // see if we actually need to draw anything
  if (isset($path) && (!isset($test['lastFrame']) || $test['lastFrame'] !== $path)) {
    $test['lastFrame'] = $path;
    $thumb = imagecreatefromjpeg("./$path");
    if ($thumb) {
      // Scale and center the thumbnail aspect-correct in the area reserved for it
      $thumb_w = imagesx($thumb);
      $thumb_h = imagesy($thumb);
      $scale = min($test['width'] / $thumb_w, $test['height'] / $thumb_h);
      $w = min(floor($thumb_w * $scale), $test['width']);
      $h = min(floor($thumb_h * $scale), $test['height']);
      $x = $test['x'] + floor(($test['width'] - $w) / 2);
      $y = $test['y'] + floor(($test['height'] - $h) / 2);
      imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $black);
      fastimagecopyresampled($im, $thumb, $x, $y, 0, 0, $w, $h, $thumb_w, $thumb_h, 4);
      imagedestroy($thumb);
      $updated = true;
    }
  }

  if (DrawFrameTime($test, $frameTime, $im, $test['x'], $test['y'] + $test['height'], $test['width']))
    $updated = true;

  return $updated;
}

function DrawFrameTime(&$test, $frameTime, $im, $x, $y, $w) {
  global $timeHeight, $black;
  static $font_size = 0;
  $updated = false;
  
  if (!$font_size)
    $font_size = GetFontSize($w, $timeHeight, "000.00");
    
  $seconds = floor($frameTime / 1000);
  $tenths = floor((($frameTime / 1000) - $seconds) * 10);
  $time = "$seconds.$tenths";
  if (!isset($test['last_time']) || $test['last_time'] !== $time) {
    $updated = true;
    $test['last_time'] = $time;
    imagefilledrectangle($im, $x, $y, $x + $w, $y + $timeHeight, $black);
    CenterText($im, $x, $y, $w, $timeHeight, $font_size, $time);
  }
  
  return $updated;
}

function GetFontSize($width, $height, $text) {
  global $font;
  $small = 0;
  $big = 100;
  $size = 50;
  do {
    $last_size = $size;
    $box = imagettfbbox($size, 0, $font, $text);
    $w = abs($box[4] - $box[0]);
    $h = abs($box[5] - $box[1]);
    if ($w < $width && $h < $height) {
      $small = $size;
      $size = floor($size + (($big - $size) / 2));
    } else {
      $big = $size;
      $size = floor($size - (($size - $small) / 2));
    }
  } while ($last_size !== $size && $size > 0);
  
  return $size;
}

function CenterText($im, $x, $y, $w, $h, $size, $text) {
  global $font, $textColor;
  $ret = null;
  if (!$size)
    $size = GetFontSize($w, $h, $text);
  if ($size) {
    $box = imagettfbbox($size, 0, $font, $text);
    $out_w = abs($box[4] - $box[0]);
    $out_h = abs($box[5] - $box[1]);
    $ascent = abs($box[7]);
    $left = floor($x + (($w - $out_w) / 2));
    $top = floor($y + (($h - $out_h) / 2)) + $ascent;
    $ret = imagettftext($im, $size, 0, $left, $top, $textColor, $font, $text);
  }
  return $ret;
}
?>
