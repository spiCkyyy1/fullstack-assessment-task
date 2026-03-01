<?php

declare(strict_types=1);

namespace App\Controller\Assessment;

use App\Domain\AssessmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Specifically handles updates to existing assessment answers.
 */
class UpdateAnswerController extends AbstractController
{
    private AssessmentService $assessmentService;

    public function __construct(AssessmentService $assessmentService)
    {
        $this->assessmentService = $assessmentService;
    }

    /**
     * PUT /api/assessment/answers/{id}
     * Updates an existing specific answer record by ID.
     */
    #[Route('/api/assessment/answers/{id}', name: 'api_assessment_answers_update', methods: ['PUT'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Logic handled via the Service layer to ensure consistency
            $answer = $this->assessmentService->updateAnswer($id, $payload);

            return $this->json([
                'status' => 'success',
                'id' => $answer->getId(),
                'message' => 'Answer updated successfully'
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            // Typically thrown if the record ID doesn't exist or validation fails
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            // General safety net for unexpected runtime issues
            return $this->json(['error' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}