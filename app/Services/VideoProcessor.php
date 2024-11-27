<?php

namespace App\Services;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\Video\VideoFilters;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class VideoProcessor
{
    protected $ffmpeg;
    protected $tempPath;
    protected $outputPath;
    protected $defaultOptions;

    public function __construct()
    {
        $this->initializePaths();
        $this->initializeFFMpeg();
        $this->setDefaultOptions();
    }

    /**
     * Initialize storage paths
     */
    private function initializePaths(): void
    {
        $this->tempPath = storage_path('app/temp');
        $this->outputPath = storage_path('app/public/processed_videos');

        // Create directories if they don't exist
        if (!file_exists($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
        if (!file_exists($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Initialize FFMpeg with configuration
     */
    private function initializeFFMpeg(): void
    {
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries'  => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
            'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
            'timeout'          => 3600, // 1 hour
            'ffmpeg.threads'   => 12,   // CPU threads to use
        ]);
    }

    /**
     * Set default processing options
     */
    private function setDefaultOptions(): void
    {
        $this->defaultOptions = [
            'subtitle_style' => [
                'fontsize' => 24,
                'fontcolor' => 'white',
                'fontname' => 'Arial',
                'box' => 1,
                'boxcolor' => 'black@0.5',
                'boxborderw' => 5,
                'line_spacing' => 1,
                'margin_v' => 20,
            ],
            'video_settings' => [
                'width' => 1280,
                'height' => 720,
                'videoBitrate' => 2000, // 2Mbps
                'audioBitrate' => 192,  // 192Kbps
            ]
        ];
    }

    /**
     * Process video with subtitles
     */
    public function processVideo(string $videoPath, array $transcriptData, array $options = []): array
    {
        try {
            // Generate unique output filename
            $outputFilename = $this->generateOutputFilename($videoPath);
            $subtitlePath = $this->generateSubtitleFile($transcriptData);
            
            // Open video
            $video = $this->ffmpeg->open($videoPath);
            
            // Get video metadata
            $metadata = $this->getVideoMetadata($video);
            
            // Merge options with defaults
            $options = array_merge_recursive($this->defaultOptions, $options);
            
            // Process video with subtitles
            $processedPath = $this->processVideoWithSubtitles(
                $video,
                $subtitlePath,
                $outputFilename,
                $options,
                $metadata
            );

            // Clean up temporary files
            $this->cleanup($subtitlePath);

            return [
                'success' => true,
                'path' => $processedPath,
                'metadata' => $metadata,
                'url' => Storage::url("processed_videos/$outputFilename")
            ];

        } catch (Exception $e) {
            Log::error('Video processing failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // Add this method to your VideoProcessor class

/**
 * Process local video without transcript
 */
public function processLocalVideo(string $videoPath): array
{
    try {
        if (!file_exists($videoPath)) {
            throw new Exception("Video file not found at path: $videoPath");
        }

        Log::info('Starting video processing', ['video_path' => $videoPath]);

        $outputFilename = $this->generateOutputFilename($videoPath);
        $outputPath = $this->outputPath . '/' . $outputFilename;

        // Updated FFmpeg command with better audio handling
        $command = sprintf(
            'ffmpeg -i %s -c:v libx264 -preset ultrafast -crf 23 -c:a aac -b:a 192k -strict experimental -ac 2 %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );

        // Alternative command if the above doesn't work
        // $command = sprintf(
        //     'ffmpeg -i %s -c:v libx264 -preset ultrafast -crf 23 -c:a copy %s 2>&1',
        //     escapeshellarg($videoPath),
        //     escapeshellarg($outputPath)
        // );

        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            Log::error('FFmpeg command failed', [
                'command' => $command,
                'output' => $output,
                'return_var' => $returnVar
            ]);
            throw new Exception("FFmpeg encoding failed: " . implode("\n", $output));
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception("Output file is missing or empty: $outputPath");
        }

        // Get basic metadata
        $metadata = [
            'filesize' => filesize($outputPath),
            'duration' => $this->getVideoDuration($videoPath)
        ];

        return [
            'success' => true,
            'path' => $outputFilename,
            'metadata' => $metadata,
            'url' => Storage::url("processed_videos/$outputFilename")
        ];

    } catch (Exception $e) {
        Log::error('Video processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'video_path' => $videoPath
        ]);

        if (isset($outputPath) && file_exists($outputPath)) {
            unlink($outputPath);
        }

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
/**
 * Get video metadata with enhanced error checking
 */
private function getVideoMetadata($video): array
{
    try {
        $stream = $video->getStreams()->videos()->first();
        
        if (!$stream) {
            throw new Exception("No video stream found");
        }

        return [
            'width' => $stream->get('width'),
            'height' => $stream->get('height'),
            'duration' => $stream->get('duration'),
            'bitrate' => $stream->get('bit_rate'),
            'codec' => $stream->get('codec_name'),
            'format' => $stream->get('format_name')
        ];
    } catch (Exception $e) {
        throw new Exception("Failed to get video metadata: " . $e->getMessage());
    }
}

    /**
     * Generate subtitle file from transcript data
     */
    private function generateSubtitleFile(array $transcriptData): string
    {
        $subtitlePath = $this->tempPath . '/' . Str::random(16) . '.srt';
        $srtContent = '';
        $index = 1;

        foreach ($transcriptData as $segment) {
            $startTime = $this->formatTimestamp($segment['start']);
            $endTime = $this->formatTimestamp($segment['start'] + $segment['duration']);
            
            $srtContent .= $index . "\n";
            $srtContent .= $startTime . ' --> ' . $endTime . "\n";
            $srtContent .= $segment['text'] . "\n\n";
            
            $index++;
        }

        file_put_contents($subtitlePath, $srtContent);
        return $subtitlePath;
    }

    /**
     * Process video with subtitles and apply filters
     */
    private function processVideoWithSubtitles($video, $subtitlePath, $outputFilename, $options, $metadata): string
    {
        // Create format
        $format = new X264('aac', 'libx264');
        
        // Set format options
        $format->setKiloBitrate($options['video_settings']['videoBitrate']);
        $format->setAudioKiloBitrate($options['video_settings']['audioBitrate']);

        // Calculate dimensions while maintaining aspect ratio
        $dimensions = $this->calculateDimensions(
            $metadata['width'],
            $metadata['height'],
            $options['video_settings']['width'],
            $options['video_settings']['height']
        );

        // Build subtitle style
        $subtitleStyle = $this->buildSubtitleStyle($options['subtitle_style']);

        // Apply filters
        $video->filters()
            ->resize(new Dimension($dimensions['width'], $dimensions['height']))
            ->custom("subtitles=$subtitlePath:force_style='" . $subtitleStyle . "'");

        // Save video
        $outputPath = $this->outputPath . '/' . $outputFilename;
        $video->save($format, $outputPath);

        return $outputFilename;
    }

    /**
     * Calculate dimensions while maintaining aspect ratio
     */
    private function calculateDimensions(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight): array
    {
        $ratio = min($targetWidth / $originalWidth, $targetHeight / $originalHeight);
        
        return [
            'width' => round($originalWidth * $ratio),
            'height' => round($originalHeight * $ratio)
        ];
    }

    /**
     * Build subtitle style string
     */
    private function buildSubtitleStyle(array $style): string
    {
        return "FontSize={$style['fontsize']},".
               "FontName={$style['fontname']},".
               "PrimaryColour={$style['fontcolor']},".
               "BoxBorderW={$style['boxborderw']},".
               "Box={$style['box']},".
               "BoxColour={$style['boxcolor']},".
               "LineSpacing={$style['line_spacing']},".
               "MarginV={$style['margin_v']}";
    }

    /**
     * Format timestamp for SRT
     */
    private function formatTimestamp(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $ms = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $ms);
    }

    /**
     * Generate unique output filename
     */
    private function generateOutputFilename(string $inputPath): string
    {
        $extension = pathinfo($inputPath, PATHINFO_EXTENSION);
        return Str::random(16) . '_' . time() . '.' . $extension;
    }

    /**
     * Clean up temporary files
     */
    private function cleanup(string $subtitlePath): void
    {
        if (file_exists($subtitlePath)) {
            unlink($subtitlePath);
        }
    }

    /**
     * Extract frames from video for thumbnail
     */
    public function extractThumbnail(string $videoPath, int $timeInSeconds = 5): ?string
    {
        try {
            $video = $this->ffmpeg->open($videoPath);
            $thumbnailPath = $this->outputPath . '/' . Str::random(16) . '.jpg';
            
            $frame = $video->frame(TimeCode::fromSeconds($timeInSeconds));
            $frame->save($thumbnailPath);

            return $thumbnailPath;

        } catch (Exception $e) {
            Log::error('Thumbnail extraction failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath
            ]);
            return null;
        }
    }

    /**
     * Get video duration
     */
    public function getVideoDuration(string $videoPath): ?float
    {
        try {
            $video = $this->ffmpeg->open($videoPath);
            return $video->getStreams()->videos()->first()->get('duration');
        } catch (Exception $e) {
            Log::error('Failed to get video duration', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath
            ]);
            return null;
        }
    }
}