<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Spatie\PdfToText\Pdf;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Services\ClaudeService;

class ResumeAnalysisService 
{

     private $claudeService;

    public function __construct(ClaudeService $claudeService)
    {
        $this->claudeService = $claudeService;
    }

    public function extractResumeInformation(string $fileUrl){

        try {
        // Extract raw text from the resume pdf file (read pdf file, and get the text)
        $rawText = $this->extractTextFromPdf($fileUrl);

        Log::debug('successfully'.strlen($rawText).'Characters');   

        // Use Claude API to organize the text into a structured format

      

            $response = $this->claudeService->sendMessage( [
                    'model' => 'claude-sonnet-4-6',
                    'max_tokens' => 1024,
                    'temperature' => 0.1, 
                    'system' => 'You are a precise resume parser. Extract information exactly as it appears in the resume without adding any interpretation or additional information. Return ONLY a valid JSON object, no extra text.', // ✅ system منفصل
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => "Parse the following resume content and extract the information as a JSON object with the exact keys: 'summary', 'skills', 'experience', 'education'. Return an empty string for any key not found. Resume content: {$rawText}"
                        ],
                    ],
            ]);

            $data = json_decode($response->getBody(), true);

            
            $text = $data['content'][0]['text'] ?? 'No text returned';

            if (empty($text)) {
                throw new \Exception('Claude returned empty content');
            }

            $text = preg_replace('/^```json\s*|\s*```$/s', '', trim($text));

            $parsed = json_decode($text, true);

            if (!is_array($parsed)) {
                Log::error('Parsed JSON is invalid', ['raw' => $text]);
                throw new \Exception('Invalid JSON returned from Claude');
            }
            
            if (json_last_error() === JSON_ERROR_NONE) {
            
                $requiredKeys = ['summary', 'skills', 'experience', 'education'];
                $missingKeys = array_diff($requiredKeys, array_keys($parsed));

                if (count($missingKeys) > 0) {
                    Log::error('Missing required keys: ' . implode(', ', $missingKeys));
                    throw new \Exception('Missing required keys in the parsed result');
                }

            
            }

            Log::debug('Claude response: ', $parsed ?? ['raw' => $text]);
        
        // Output: summary, skills, experience, education -> JSON

        // Return the JSON object
        return [
                'summary'    => is_array($parsed['summary'] ?? '') ? json_encode($parsed['summary']) : ($parsed['summary'] ?? ''),
                'skills'     => is_array($parsed['skills'] ?? '') ? json_encode($parsed['skills']) : ($parsed['skills'] ?? ''),
                'experience' => is_array($parsed['experience'] ?? '') ? json_encode($parsed['experience']) : ($parsed['experience'] ?? ''),
                'education'  => is_array($parsed['education'] ?? '') ? json_encode($parsed['education']) : ($parsed['education'] ?? ''),
            ];

        } catch (\Exception $e) {
            Log::error('Error extracting resume information: '.$e->getMessage());
            
            return [
                'summary'=>'',
                'skills'=>'',
                'experience'=>'',
                'education'=>'',
            ];

        }

    }



    public function analyzeResume($jobVacancy, $resumeData){
        try {
            
        
        $jobDetails = json_encode([
            'job_title'=> $jobVacancy->title,
            'job_description'=> $jobVacancy->description,
            'job_location'=> $jobVacancy->location,
            'job_type'=> $jobVacancy->type,
            'job_salary'=> $jobVacancy->salary,
        ]);

        $resumeDetails = json_encode($resumeData);


        $response = $this->claudeService->sendMessage( [
                    'model' => 'claude-sonnet-4-6',
                    'max_tokens' => 1024,
                    'temperature' => 0.1, 
                    'system' =>    "You are an expert HR professional and job recruiter.
                                    You are given a job vacancy and a resume.
                                    Your task is to analyze the resume and determine if the candidate is a good fit for the job.
                                    The output must be in JSON format.
                                    Provide a score from 0 to 100 for the candidate's suitability for the job, and concise feedback.
                                    Response should only be JSON that has the following keys: 'aiGeneratedScore', 'aiGeneratedFeedback'.
                                    For aiGeneratedFeedback follow these rules strictly:
                                    - Plain text only: no markdown, no asterisks, no bold, no bullet symbols, no dashes, no special characters.
                                    - Use short paragraphs or numbered lines (1. 2. 3.) if listing points.
                                    - Keep the total feedback under 120 words.
                                    - Be specific to the job and the candidate's resume.",
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => "Please evaluate this job application. Job Details: {$jobDetails}. Resume Details: {$resumeDetails}."
                        ],
                    ],
            ]);



        $data = json_decode($response->getBody(), true);

        $text = $data['content'][0]['text'];

        $text = preg_replace('/^```json\s*|\s*```$/s', '', trim($text));

        Log::debug('Claude Evaluation Response: '.$text);

        if (empty($text)) {
                throw new \Exception('Claude returned empty content');
            }

            // {!! nl2br(e($result['aiGeneratedFeedback'])) !!}


        $parsed = json_decode($text, true);

            if (!is_array($parsed)) {
                Log::error('Parsed JSON is invalid', ['raw' => $text]);
                throw new \Exception('Invalid JSON returned from Claude');
            }


            if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse OpenAI response: ' . json_last_error_msg());
            throw new \Exception('Failed to parse Claude response');
        }

        if(!isset($parsed['aiGeneratedScore']) || !isset($parsed['aiGeneratedFeedback'])) {
            Log::error('Missing required keys in the parsed result');
            throw new \Exception('Missing required keys in the parsed result');
        }

        return $parsed;



        } catch (\Exception $e) {
            Log::error('Error analyzing resume: ' . $e->getMessage());
            return [
                'aiGeneratedScore' => 0,
                'aiGeneratedFeedback' => 'An error occurred while analyzing the resume. Please try again later.'
            ];
        }

    }

    

    private function extractTextFromPdf(string $fileUrl): string {
    $tempFile = tempnam(sys_get_temp_dir(), 'resume');

    $filePath = parse_url($fileUrl, PHP_URL_PATH);
    if (!$filePath) {
        throw new \Exception('Invalid file URL');
    }

    $filename = basename($filePath);
    $storagePath = "resumes/{$filename}";

    if (!Storage::disk('cloud')->exists($storagePath)) {
        throw new \Exception('File not found in storage: ' . $storagePath);
    }

    $pdfContent = Storage::disk('cloud')->get($storagePath);
    if (!$pdfContent) {
        throw new \Exception('Failed to read file');
    }

    file_put_contents($tempFile, $pdfContent);

    // Auto-detect pdftotext on both Windows and Linux
    $pdfToTextPath = null;

    $windowsPaths = [
        'C:/poppler-25.12.0/Library/bin/pdftotext.exe',
        'C:/poppler/bin/pdftotext.exe',
    ];

    foreach ($windowsPaths as $path) {
        if (file_exists($path)) {
            $pdfToTextPath = $path;
            break;
        }
    }

    // On Linux (Laravel Cloud), use system pdftotext
    if (!$pdfToTextPath) {
        $linuxPath = trim(shell_exec('which pdftotext') ?? '');
        if (!empty($linuxPath) && file_exists($linuxPath)) {
            $pdfToTextPath = $linuxPath;
        }
    }

    if (!$pdfToTextPath) {
        throw new \Exception('pdftotext is not installed on this server.');
    }

    $instance = new Pdf($pdfToTextPath);
    $instance->setPdf($tempFile);
    $text = $instance->text();

    unlink($tempFile);

    return $text;
}


}
