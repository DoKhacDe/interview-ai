<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\Document;
use App\Models\InterviewSession;
use App\Models\Message;
use App\Services\DocumentService;
use App\Services\OpenAIService;
use App\Events\MessageSent;

new class extends Component
{
    use WithFileUploads;

    public $jdFile;
    public $cvFile;
    public $questionsFile;
    
    public $session;
    public $userMessage = '';
    public $isProcessing = false;

    public function mount()
    {
        if (session()->has('interview_session_id')) {
            $this->session = InterviewSession::find(session('interview_session_id'));
        }
    }

    public function startInterview(DocumentService $documentService, OpenAIService $openAIService)
    {
        // \Log::info('Bắt đầu startInterview');
        $this->validate([
            'jdFile' => 'nullable|file|mimes:pdf,docx,txt|max:10240',
            'cvFile' => 'required|file|mimes:pdf,docx,txt|max:10240',
            'questionsFile' => 'nullable|file|mimes:pdf,docx,txt|max:10240',
        ]);
        // \Log::info('Validation thành công');

        try {
            $jd = null;
            if ($this->jdFile) {
                // \Log::info('Đang xử lý JD');
                $jd = $documentService->process($this->jdFile, 'jd');
            }

            // \Log::info('Đang xử lý CV');
            $cv = $documentService->process($this->cvFile, 'cv');

            $questions = null;
            if ($this->questionsFile) {
                // \Log::info('Đang xử lý Questions');
                $questions = $documentService->process($this->questionsFile, 'questions');
            }

            // \Log::info('Đang tạo InterviewSession');
            $this->session = InterviewSession::create([
                'jd_id' => $jd ? $jd->id : null,
                'cv_id' => $cv->id,
                'questions_id' => $questions ? $questions->id : null,
            ]);

            // Save session ID to browser session to persist across reloads
            session(['interview_session_id' => $this->session->id]);

            $this->isProcessing = true;
            
            // \Log::info('Đang lấy phản hồi từ OpenAI');
            // Initial AI message
            $aiResponse = $openAIService->getResponse($this->session);
            // \Log::info('Đã nhận phản hồi từ OpenAI');
            
            $message = Message::create([
                'interview_session_id' => $this->session->id,
                'role' => 'assistant',
                'content' => $aiResponse,
            ]);

            // \Log::info('Đang broadcast MessageSent');
            broadcast(new MessageSent($message))->toOthers();
            
            $this->isProcessing = false;
            // \Log::info('Hoàn thành startInterview');
        } catch (\Exception $e) {
            \Log::error('Lỗi trong startInterview: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            // Sanitize error message to prevent JSON encoding issues
            $errorMessage = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
            $this->addError('session', 'Có lỗi xảy ra khi khởi tạo buổi phỏng vấn: ' . $errorMessage);
            $this->isProcessing = false;
        }
    }

    public function sendMessage(OpenAIService $openAIService)
    {
        if (empty($this->userMessage) || !$this->session) return;

        $content = $this->userMessage;
        $this->userMessage = '';

        $message = Message::create([
            'interview_session_id' => $this->session->id,
            'role' => 'user',
            'content' => $content,
        ]);

        broadcast(new MessageSent($message))->toOthers();
        
        $this->isProcessing = true;

        try {
            $aiResponse = $openAIService->getResponse($this->session);
            
            $aiMessage = Message::create([
                'interview_session_id' => $this->session->id,
                'role' => 'assistant',
                'content' => $aiResponse,
            ]);

            broadcast(new MessageSent($aiMessage))->toOthers();
        } catch (\Exception $e) {
            \Log::error('Lỗi khi gửi tin nhắn: ' . $e->getMessage());
        }
        
        $this->isProcessing = false;
        $this->dispatch('message-sent');
    }

    public function getListeners()
    {
        if (!$this->session) return [];
        
        return [
            "echo:interview.{$this->session->id},MessageSent" => 'handleMessageSent',
        ];
    }

    public function handleMessageSent()
    {
        $this->dispatch('message-sent');
    }

    public function loadSession($id)
    {
        $this->session = InterviewSession::find($id);
        if ($this->session) {
            session(['interview_session_id' => $this->session->id]);
        }
    }

    public function createNewSession()
    {
        session()->forget('interview_session_id');
        $this->session = null;
    }

    public function endInterview()
    {
        $this->createNewSession();
    }

    public function with()
    {
        return [
            'messages' => $this->session ? $this->session->messages()->orderBy('created_at', 'asc')->get() : []
        ];
    }
};
?>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    <!-- Sidebar -->
    <div class="w-80 bg-white border-r border-gray-200 flex flex-col flex-shrink-0 z-20 shadow-lg">
        <div class="p-5 border-b border-gray-100 bg-white z-10">
            <h1 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white">AI</span>
                Phỏng vấn
            </h1>
            <button wire:click="createNewSession" class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold transition-all shadow-lg shadow-indigo-200 transform active:scale-95">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Buổi phỏng vấn mới
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-4 space-y-3 scrollbar-thin scrollbar-thumb-gray-200">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 px-1">Lịch sử phỏng vấn</div>
            @foreach(\App\Models\InterviewSession::with(['cv', 'jd'])->latest()->get() as $historySession)
                <div wire:click="loadSession({{ $historySession->id }})" 
                     class="group cursor-pointer p-4 rounded-xl transition-all border relative {{ $session && $session->id === $historySession->id ? 'bg-indigo-50 border-indigo-200 shadow-sm' : 'bg-white border-gray-100 hover:border-indigo-200 hover:shadow-md' }}">
                    
                    @if($session && $session->id === $historySession->id)
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-indigo-500 rounded-l-xl"></div>
                    @endif

                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full {{ $historySession->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                            #{{ $historySession->id }}
                        </span>
                        <span class="text-[10px] text-gray-400 font-medium">{{ $historySession->created_at->diffForHumans() }}</span>
                    </div>
                    
                    <h4 class="font-semibold text-gray-800 text-sm line-clamp-2 mb-1 group-hover:text-indigo-600 transition-colors">
                        {{ $historySession->cv ? $historySession->cv->name : 'Chưa có CV' }}
                    </h4>
                    
                    @if($historySession->jd)
                        <p class="text-xs text-gray-500 line-clamp-1 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            {{ $historySession->jd->name }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-full overflow-hidden relative">
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-5xl mx-auto h-full" wire:key="interview-container-{{ $session ? $session->id : 'setup' }}">
    @if (!$session)
        <div class="bg-white shadow-2xl rounded-3xl overflow-hidden border border-gray-100 transition-all hover:shadow-indigo-100/50" wire:key="setup-form">
            <div class="bg-indigo-600 px-8 py-10 text-white">
                <h2 class="text-3xl font-extrabold tracking-tight">Chuẩn bị phỏng vấn</h2>
                <p class="mt-2 text-indigo-100 text-lg">Tải lên các tài liệu cần thiết để AI có thể hỗ trợ bạn tốt nhất.</p>
            </div>
            
            <div class="p-8 space-y-8">
                @error('session')
                    <div class="bg-red-50 border-l-4 border-red-400 p-4 text-red-700 text-sm">
                        {{ $message }}
                    </div>
                @enderror

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- JD Upload -->
                    <div class="relative group">
                        <label class="block text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span class="w-8 h-8 bg-indigo-100 text-indigo-600 rounded-lg flex items-center justify-center text-xs">01</span>
                            JD Công ty (Tùy chọn)
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-2xl group-hover:border-indigo-400 transition-colors bg-gray-50/50">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400 group-hover:text-indigo-500 transition-colors" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none">
                                        <span>Tải file lên</span>
                                        <input wire:model="jdFile" type="file" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PDF, DOCX hoặc TXT</p>
                                @if($jdFile) <p class="text-xs text-green-600 font-semibold mt-2">✓ {{ $jdFile->getClientOriginalName() }}</p> @endif
                            </div>
                        </div>
                        @error('jdFile') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <!-- CV Upload -->
                    <div class="relative group">
                        <label class="block text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span class="w-8 h-8 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center text-xs">02</span>
                            CV Ứng viên
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-2xl group-hover:border-emerald-400 transition-colors bg-gray-50/50">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400 group-hover:text-emerald-500 transition-colors" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label class="relative cursor-pointer rounded-md font-medium text-emerald-600 hover:text-emerald-500 focus-within:outline-none">
                                        <span>Tải file lên</span>
                                        <input wire:model="cvFile" type="file" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PDF, DOCX hoặc TXT</p>
                                @if($cvFile) <p class="text-xs text-green-600 font-semibold mt-2">✓ {{ $cvFile->getClientOriginalName() }}</p> @endif
                            </div>
                        </div>
                        @error('cvFile') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <!-- Questions Upload -->
                    <div class="relative group">
                        <label class="block text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span class="w-8 h-8 bg-amber-100 text-amber-600 rounded-lg flex items-center justify-center text-xs">03</span>
                            Câu hỏi mẫu (Tùy chọn)
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-2xl group-hover:border-amber-400 transition-colors bg-gray-50/50">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400 group-hover:text-amber-500 transition-colors" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label class="relative cursor-pointer rounded-md font-medium text-amber-600 hover:text-amber-500 focus-within:outline-none">
                                        <span>Tải file lên</span>
                                        <input wire:model="questionsFile" type="file" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PDF, DOCX hoặc TXT</p>
                                @if($questionsFile) <p class="text-xs text-green-600 font-semibold mt-2">✓ {{ $questionsFile->getClientOriginalName() }}</p> @endif
                            </div>
                        </div>
                        @error('questionsFile') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-center pt-8 border-t border-gray-100">
                    <button wire:click="startInterview" wire:loading.attr="disabled" class="group relative px-12 py-4 bg-indigo-600 text-white text-lg font-bold rounded-2xl shadow-xl shadow-indigo-200 hover:bg-indigo-700 hover:shadow-indigo-300 transform transition active:scale-95 disabled:opacity-50 flex items-center gap-3">
                        <span wire:loading.remove wire:target="startInterview">Bắt đầu ngay</span>
                        <span wire:loading wire:target="startInterview" class="flex items-center gap-2">
                            <svg class="animate-spin h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Đang chuẩn bị...
                        </span>
                        <svg wire:loading.remove wire:target="startInterview" class="w-6 h-6 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden flex flex-col h-[750px] border border-gray-100" wire:key="chat-window-{{ $session->id }}">
            <!-- Header -->
            <div class="bg-white px-8 py-5 border-b flex justify-between items-center shadow-sm z-10">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                            </svg>
                        </div>
                        <span class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 border-2 border-white rounded-full"></span>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 text-lg">Hỏi gì trả lời đấy</h3>
                        <p class="text-xs font-medium text-gray-500 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                            Đang trực tuyến
                        </p>
                    </div>
                </div>
                <button 
                    wire:click="endInterview" 
                    wire:confirm="Bạn có chắc chắn muốn kết thúc buổi phỏng vấn này?"
                    class="px-4 py-2 text-sm font-semibold text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-xl transition-all"
                >
                    Kết thúc
                </button>
            </div>

            <!-- Messages Container -->
            <div id="chat-container" class="flex-1 overflow-y-auto px-8 py-6 space-y-6 bg-slate-50/50 scroll-smooth">
                @foreach($messages as $msg)
                    <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }} animate-fade-in" wire:key="msg-{{ $msg->id }}">
                        <div class="flex flex-col {{ $msg->role === 'user' ? 'items-end' : 'items-start' }} max-w-[85%]">
                            <div class="rounded-2xl px-5 py-3 shadow-md {{ $msg->role === 'user' ? 'bg-indigo-600 text-white rounded-tr-none' : 'bg-white text-gray-800 border border-gray-100 rounded-tl-none' }}">
                                <p class="text-base leading-relaxed whitespace-pre-wrap">{{ $msg->content }}</p>
                            </div>
                            <span class="text-[11px] mt-1.5 font-medium {{ $msg->role === 'user' ? 'text-indigo-400 mr-1' : 'text-gray-400 ml-1' }}">
                                {{ $msg->created_at->format('H:i') }}
                            </span>
                        </div>
                    </div>
                @endforeach
                
                @if($isProcessing)
                    <div class="flex justify-start animate-fade-in" wire:key="typing-indicator">
                        <div class="bg-white border border-gray-100 rounded-2xl rounded-tl-none px-5 py-4 shadow-md">
                            <div class="flex gap-1.5">
                                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0s"></div>
                                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                                <div class="w-2 h-2 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
                            </div>
                        </div>
                    </div>
                @endif
                <div id="anchor"></div>
            </div>

            <!-- Input Area -->
            <div class="px-8 py-6 bg-white border-t border-gray-100 shadow-[0_-4px_20px_-5px_rgba(0,0,0,0.05)]">
                <form wire:submit.prevent="sendMessage" class="flex gap-4 items-end">
                    <div class="flex-1 relative">
                        <textarea 
                            wire:model="userMessage" 
                            wire:keydown.enter.prevent="$event.shiftKey ? null : $wire.sendMessage()"
                            placeholder="Nhập câu trả lời của bạn tại đây... (Shift + Enter để xuống dòng)" 
                            rows="1"
                            class="w-full bg-gray-50 border-transparent rounded-2xl focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 text-base py-4 px-6 transition-all resize-none overflow-hidden" 
                            style="min-height: 56px; max-height: 200px;"
                            oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"
                            {{ $isProcessing ? 'disabled' : '' }}
                        ></textarea>
                    </div>
                    <button type="submit" 
                            class="bg-indigo-600 hover:bg-indigo-700 text-white p-4 rounded-2xl transition-all shadow-lg shadow-indigo-100 disabled:opacity-50 disabled:shadow-none transform active:scale-95 flex-shrink-0 h-14 w-14 flex items-center justify-center" 
                            {{ $isProcessing ? 'disabled' : '' }}>
                        <svg class="w-6 h-6 transform rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>

        <script>
            document.addEventListener('livewire:initialized', () => {
                const container = document.getElementById('chat-container');
                const scrollToBottom = () => {
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                };
                
                scrollToBottom();
                
                Livewire.on('message-sent', () => {
                    setTimeout(scrollToBottom, 50);
                });
            });
        </script>
    @endif
</div>
        </div>
    </div>
</div>
