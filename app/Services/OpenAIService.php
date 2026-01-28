<?php

namespace App\Services;

use App\Models\InterviewSession;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAIService
{
    public function getResponse(InterviewSession $session)
    {
        $messages = $session->messages()->orderBy('created_at', 'asc')->get();
        
        $systemPrompt = $this->buildSystemPrompt($session);
        
        $chatMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $msg) {
            $chatMessages[] = ['role' => $msg->role, 'content' => $msg->content];
        }

        $result = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $chatMessages,
        ]);

        return $result->choices[0]->message->content;
    }

    private function buildSystemPrompt(InterviewSession $session): string
    {
        $jd = $session->jd ? $session->jd->content : 'Không có thông tin JD cụ thể. Hãy phỏng vấn dựa trên các tiêu chuẩn chung cho vị trí phù hợp với CV của ứng viên.';
        $cv = $session->cv->content;
        $questions = $session->questions ? $session->questions->content : 'Không có danh sách câu hỏi gợi ý. Hãy tự đặt các câu hỏi chuyên môn và kỹ năng mềm dựa trên nội dung CV của ứng viên và yêu cầu chung của ngành.';

        $prompt = "Bạn là một người phỏng vấn chuyên nghiệp. Bạn đang thực hiện một buổi phỏng vấn mô phỏng.\n\n";
        
        if ($session->jd) {
            $prompt .= "Dưới đây là thông tin về vị trí công việc (JD):\n$jd\n\n";
        } else {
            $prompt .= "Lưu ý: Không có JD cụ thể, hãy đóng vai người phỏng vấn cho vị trí phù hợp nhất với kinh nghiệm của ứng viên trong CV.\n\n";
        }

        $prompt .= "Dưới đây là CV của ứng viên:\n$cv\n\n";

        if ($session->questions) {
            $prompt .= "Dưới đây là danh sách các câu hỏi gợi ý cho buổi phỏng vấn này:\n$questions\n\n";
        } else {
            $prompt .= "Lưu ý: Không có danh sách câu hỏi sẵn, hãy tự chủ động đặt câu hỏi để khai thác năng lực ứng viên.\n\n";
        }

        $prompt .= "Nhiệm vụ của bạn:
1. Đóng vai người phỏng vấn, dựa vào CV (và JD nếu có) để đưa ra các câu hỏi phù hợp.
2. Bạn có thể sử dụng danh sách câu hỏi gợi ý (nếu có) hoặc tự đặt câu hỏi dựa trên nội dung CV của ứng viên.
3. Hãy hỏi từng câu một, chờ ứng viên trả lời rồi mới tiếp tục.
4. Đưa ra phản hồi hoặc câu hỏi đào sâu dựa trên câu trả lời của ứng viên.
5. Luôn giữ thái độ chuyên nghiệp, nghiêm túc nhưng mang tính xây dựng.
6. Hãy bắt đầu bằng cách chào hỏi và giới thiệu ngắn gọn về buổi phỏng vấn này.";

        return $prompt;
    }
}
