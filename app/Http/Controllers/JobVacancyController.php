<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use App\Models\Resume;
use App\Services\ResumeAnalysisService;
use Illuminate\Http\Request;
use App\Models\JobVacancy;


use OpenAI\Laravel\Facades\OpenAI;
use GuzzleHttp\Client;
use App\Http\Requests\ApplyJobRequest;

class JobVacancyController extends Controller
{

    protected $resumeAnalysisService;

    public function __construct(ResumeAnalysisService $resumeAnalysisService){
        $this->resumeAnalysisService =$resumeAnalysisService;
    }




    public function show(string $id){
        $jobVacancy = JobVacancy::findOrFail($id);
         $jobVacancy->increment('viewCount');
        return view('job-vacancies.show',compact('jobVacancy'));
    }
    public function apply(string $id){
        $jobVacancy = JobVacancy::findOrFail($id);
        $resumes = auth()->user()->resumes()->get();
        return view('job-vacancies.apply',compact('jobVacancy','resumes'));
    }

    public function processApplication(ApplyJobRequest $request,string $id){

        $jobVacancy = JobVacancy::findOrFail($id);
        $resumeId = null;
        $extractedInfo = null;

        if($request->input('resume_option') === 'new_resume'){
            $file = $request->file('resume_file');
            $extension = $file->getClientOriginalExtension();
            $originalFileName = $file->getClientOriginalName();
            $fileName = 'resume_'.time().'.'.$extension;
            
            // Store in Laravel Cloud
            $path = $file->storeAs('resumes',$fileName,'cloud');

          $fileUrl = config('filesystems.disks.cloud.url').'/'.$path;

            // Extract information from the resume

            $extractedInfo = $this->resumeAnalysisService->extractResumeInformation($fileUrl);

            $resume = Resume::create([
                'filename'=>$originalFileName,
                'fileUrl'=>$path,
                'userId'=>auth()->id(),
                'contactDetails'=>json_encode([
                    'name'=>auth()->user()->name,
                    'email'=>auth()->user()->email,
                ]),
                'summary'=>$extractedInfo['summary'],
                'skills'=>$extractedInfo['skills'],
                'experience'=>$extractedInfo['experience'],
                'education'=>$extractedInfo['education'],
                
            ]);

           $resumeId =$resume->id;

        } else{
            $resumeId = $request->input('resume_option');
            $resume = Resume::findOrFail($resumeId);

            $extractedInfo = [
                'summary'=>$resume->summary,
                'skills'=>$resume->skills,
                'experience'=>$resume->experience,
                'education'=>$resume->education,
            ];

        }

        // Evaluate Job Application
        $evaluation = $this->resumeAnalysisService->analyzeResume($jobVacancy,$extractedInfo);

         JobApplication::create([
                'status'=>'pending',
                'jobVacancyId'=>$id,
                'resumeId'=>$resume->id,
                'userId'=>auth()->id(),
                'aiGeneratedScore'=>$evaluation['aiGeneratedScore'],
                'aiGeneratedFeedback'=>$evaluation['aiGeneratedFeedback'],
            ]);

        return redirect()->route('job-applications.index',$id)->with('success','Applicantion submitted successfully!');

    }

    public function testClaude(){
           
          try {
        $client = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'headers' => [
                'x-api-key' => env('CLAUDE_API_KEY'),
                'Content-Type' => 'application/json',
                'Anthropic-Version' => '2023-06-01',
            ],
        ]);

        $response = $client->post('v1/messages', [
            'json' => [
                'model' => 'claude-sonnet-4-6', 
                'messages' => [
                    ["role" => "user", "content" => "Hello Claude, tell me a joke!"]
                ],
                'max_tokens' => 200
            ]
        ]);

        $data = json_decode($response->getBody(), true);

    
        echo $data['completion'] ?? ($data['content'][0]['text'] ?? 'No text returned');

    } catch (\Exception $e) {
        dd($e->getMessage());
    }

    }

    
}
