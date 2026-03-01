<?php

declare(strict_types=1);

namespace App\Controller\Assessment;

use App\Domain\AssessmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AnswerSubmissionController extends AbstractController
{
    private AssessmentService $assessmentService;

    public function __construct(AssessmentService $assessmentService)
    {
        $this->assessmentService = $assessmentService;
    }

    /**
     * @Route("/api/assessment/answers", methods={"POST"})
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $answer = $this->assessmentService->submitAnswer($data);

            return $this->json([
                'status' => 'success',
                'answer_id' => $answer->getId(),
                'message' => 'Answer recorded successfully'
            ], Response::HTTP_CREATED);

        } catch (\InvalidArgumentException $e) {
            // Return 422 for validation/business logic failures
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            // General error handler
            return $this->json(['error' => 'An internal error occurred.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}