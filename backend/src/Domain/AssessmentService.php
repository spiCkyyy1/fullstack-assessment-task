<?php

declare(strict_types=1);

namespace App\Domain;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AssessmentService
{
    private AssessmentRepository $assessmentRepository;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        AssessmentRepository $assessmentRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    )
    {
        $this->assessmentRepository = $assessmentRepository;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function getAssessmentResults(AssessmentInstance $instance): array
    {
        $answers = $this->assessmentRepository->findAllAssessmentInstanceAnswers($instance);
        $questions = $instance->getSession()->getAssessment()?->getQuestions()->toArray() ?? [];

        $progressData = $this->getProgressAndScore($instance);

        $results = [
            'instance' => [
                'id' => $instance->getId(),
                'created_at' => $instance->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => $instance->getUpdatedAt()?->format('Y-m-d H:i:s'),
                'completed' => $instance->isCompleted(),
                'completed_at' => $instance->getCompletedAt()?->format('Y-m-d H:i:s'),
                'responder_name' => $instance->getResponderName(),
                'element' => $instance->getSession()->getAssessment()?->getElement(),
            ],
            'total_questions' => $progressData['total_questions'],
            'answered_questions' => $progressData['answered_questions'],
            'completion_percentage' => $progressData['completion_percentage'],
            'scores' => $progressData['scores'],
            'element_scores' => $progressData['element_scores'],
            'insights' => [],
        ];

        $answersByQuestion = [];
        foreach ($answers as $answer) {
            if ($answer->getAssessmentAnswerOption()) {
                $question = $answer->getAssessmentAnswerOption()->getAssessmentQuestion();
                $questionId = $question->getId();

                if (!isset($answersByQuestion[$questionId]) ||
                    $answer->getCreatedAt() > $answersByQuestion[$questionId]->getCreatedAt()) {
                    $answersByQuestion[$questionId] = $answer;
                }
            }
        }

        $results['insights'] = $this->generateInsights($instance, $answersByQuestion, $questions);

        return $results;
    }

    public function getProgressAndScore(AssessmentInstance $instance): array
    {
        $session = $instance->getSession();
        if (!$session) {
            throw new \InvalidArgumentException("Assessment instance is missing a valid session.");
        }

        $assessment = $session->getAssessment();
        if (!$assessment) {
            throw new \InvalidArgumentException("Session is not linked to a valid assessment template.");
        }
        $answers = $this->assessmentRepository->findAllAssessmentInstanceAnswers($instance);
        $questions = $assessment->getQuestions()->toArray() ?? [];
        $assessmentElement = $assessment->getElement();

        $totalQuestions = count($questions);

        $answersByQuestion = [];
        foreach ($answers as $answer) {
            if ($answer->getAssessmentAnswerOption()) {
                $question = $answer->getAssessmentAnswerOption()->getAssessmentQuestion();
                $questionId = $question->getId();

                if (!isset($answersByQuestion[$questionId]) ||
                    $answer->getCreatedAt() > $answersByQuestion[$questionId]->getCreatedAt()) {
                    $answersByQuestion[$questionId] = $answer;
                }
            }
        }

        $totalScore = 0;
        $maxScore = 0;
        $questionAnswersData = [];
        $elementScores = [];

        // Group questions by their element
        $questionsByElement = [];
        foreach ($questions as $question) {
            $questionElement = $question->getElement();
            if ($questionElement) {
                if (!isset($questionsByElement[$questionElement])) {
                    $questionsByElement[$questionElement] = [];
                }
                $questionsByElement[$questionElement][] = $question;
            }
        }

        // Calculate scores for each element
        foreach ($questionsByElement as $element => $elementQuestions) {
            $elementTotalScore = 0;
            $elementMaxScore = 0;
            $elementAnsweredQuestions = 0;
            $elementQuestionAnswersData = [];

            foreach ($elementQuestions as $question) {
                $questionId = $question->getId();
                $answer = $answersByQuestion[$questionId] ?? null;

                $options = $this->assessmentRepository->findAssessmentAnswerOptionsByQuestion($question);
                $questionMaxScore = 0;
                if (!empty($options)) {
                    $questionMaxScore = max(array_map(fn($option) => $option->getValue(), $options));
                    $elementMaxScore += $questionMaxScore;
                    $maxScore += $questionMaxScore;
                }

                $questionData = [
                    'question_id' => $questionId,
                    'question_title' => $question->getTitle(),
                    'question_suite' => $question->getQuestionSuite(),
                    'question_sequence' => $question->getSequence(),
                    'is_reflection' => $question->getIsReflection(),
                    'reflection_prompt' => $question->getReflectionPrompt(),
                    'element' => $question->getElement(),
                    'max_score' => $questionMaxScore,
                    'is_answered' => $answer !== null,
                    'answer_id' => $answer?->getId(),
                    'answer_value' => null,
                    'answer_text' => null,
                    'answer_option_id' => null,
                    'text_answer' => $answer?->getTextAnswer(),
                    'numeric_value' => $answer?->getNumericValue(),
                ];

                if ($answer && $answer->getAssessmentAnswerOption()) {
                    $answerOption = $answer->getAssessmentAnswerOption();
                    $questionData['answer_value'] = $answerOption->getValue();
                    $questionData['answer_text'] = $answerOption->getAnswer();
                    $questionData['answer_option_id'] = $answerOption->getId();
                    $questionData['answer_explanation'] = $answerOption->getExplanation();
                    $questionData['option_number'] = $answerOption->getOptionNumber();

                    $elementTotalScore += $answerOption->getValue();
                    $totalScore += $answerOption->getValue();
                    $elementAnsweredQuestions++;
                }

                $elementQuestionAnswersData[] = $questionData;
                $questionAnswersData[] = $questionData;
            }

            // Calculate element-specific scores
            $elementCompletionPercentage = count($elementQuestions) > 0
                ? round(($elementAnsweredQuestions / count($elementQuestions)) * 100, 2)
                : 0;
            // Normalize 1-5 scale to 0-100% scale
            $normalizedElementScore = $elementAnsweredQuestions > 0
                ? ($elementTotalScore - $elementAnsweredQuestions)
                : 0;
            $normalizedElementMaxScore = $elementAnsweredQuestions > 0
                ? ($elementMaxScore - $elementAnsweredQuestions)
                : 0;
            $elementScorePercentage = $normalizedElementMaxScore > 0
                ? round(($normalizedElementScore / $normalizedElementMaxScore) * 100, 2)
                : 0;

            $elementScores[$element] = [
                'element' => $element,
                'total_questions' => count($elementQuestions),
                'answered_questions' => $elementAnsweredQuestions,
                'completion_percentage' => min($elementCompletionPercentage, 100),
                'scores' => [
                    'total_score' => $elementTotalScore,
                    'max_score' => $elementMaxScore,
                    'percentage' => $elementScorePercentage,
                ],
                'question_answers' => $elementQuestionAnswersData,
            ];
        }

        usort($questionAnswersData, function($a, $b) {
            return $a['question_sequence'] <=> $b['question_sequence'];
        });

        $answeredQuestions = count($answersByQuestion);
        $completionPercentage = $totalQuestions > 0
            ? round(($answeredQuestions / $totalQuestions) * 100, 2)
            : 0;
        // Normalize 1-5 scale to 0-100% scale for overall score
        $normalizedTotalScore = $answeredQuestions > 0
            ? ($totalScore - $answeredQuestions)
            : 0;
        $normalizedTotalMaxScore = $answeredQuestions > 0
            ? ($maxScore - $answeredQuestions)
            : 0;
        $scorePercentage = $normalizedTotalMaxScore > 0
            ? round(($normalizedTotalScore / $normalizedTotalMaxScore) * 100, 2)
            : 0;

        return [
            'total_questions' => $totalQuestions,
            'answered_questions' => $answeredQuestions,
            'completion_percentage' => min($completionPercentage, 100), // Cap at 100%
            'scores' => [
                'element' => $assessmentElement,
                'total_score' => $totalScore,
                'max_score' => $maxScore,
                'percentage' => $scorePercentage,
            ],
            'question_answers' => $questionAnswersData,
            'element_scores' => $elementScores,
        ];
    }

    private function generateInsights(AssessmentInstance $instance, array $answersByQuestion, array $questions): array
    {
        $insights = [];
        $element = $instance->getSession()->getAssessment()->getElement();

        // Basic completion insight
        $completionRate = count($answersByQuestion) / max(count($questions), 1);
        if ($completionRate >= 1.0) {
            $insights[] = [
                'type' => 'completion',
                'message' => "You have completed your self-assessment for element {$element}.",
                'positive' => true,
            ];
        } else {
            $remaining = count($questions) - count($answersByQuestion);
            $insights[] = [
                'type' => 'completion',
                'message' => "You have {$remaining} questions remaining to complete this assessment.",
                'positive' => false,
            ];
        }

        $scores = [];
        foreach ($answersByQuestion as $questionId => $answer) {
            if ($answer->getAssessmentAnswerOption()) {
                $scores[] = $answer->getAssessmentAnswerOption()->getValue();
            }
        }

        if (!empty($scores)) {
            $averageScore = array_sum($scores) / count($scores);

            if ($averageScore >= 4) {
                $insights[] = [
                    'type' => 'performance',
                    'message' => "You demonstrate strong confidence in this element of teaching practice.",
                    'positive' => true,
                ];
            } elseif ($averageScore >= 3) {
                $insights[] = [
                    'type' => 'performance',
                    'message' => "You show good understanding with some areas for development.",
                    'positive' => true,
                ];
            } else {
                $insights[] = [
                    'type' => 'performance',
                    'message' => "This element may benefit from further reflection and development.",
                    'positive' => false,
                ];
            }
        }

        return $insights;
    }

    /**
     * Handles the business logic for submitting an assessment answer.
     * Validates relationships and question types before persisting.
     */
    public function submitAnswer(array $data): AssessmentAnswer
    {
        try {
            $instanceId = $data['instance_id'] ?? null;
            $questionId = $data['question_id'] ?? null;
            $answerOptionId = $data['answer_option_id'] ?? null;
            $textAnswer = $data['text_answer'] ?? null;

            // Verify Instance exists
            $instance = $this->assessmentRepository->findAssessmentInstanceById((string)$instanceId);
            if (!$instance) {
                throw new \InvalidArgumentException("Assessment instance not found.");
            }

            // Verify Question exists and belongs to this specific assessment template
            /** @var AssessmentQuestion|null $question */
            $question = $this->entityManager->getRepository(AssessmentQuestion::class)->find($questionId);

            if (!$question) {
                throw new \InvalidArgumentException("Question not found.");
            }


            $session = $instance->getSession();
            if (!$session) {
                throw new \InvalidArgumentException("Assessment instance is missing a valid session.");
            }

            $assessment = $session->getAssessment();
            if (!$assessment) {
                throw new \InvalidArgumentException("Session is not linked to a valid assessment template.");
            }

            if (!$assessment || !$assessment->getQuestions()->contains($question)) {
                throw new \InvalidArgumentException("This question does not belong to the linked assessment.");
            }

            $this->logger->info('Attempting answer submission', [
                'instance_id' => $data['instance_id'],
                'question_id' => $data['question_id']
            ]);

            // Type-specific validation (Likert vs Reflection)
            $option = null;
            if ($question->getQuestionType() === 'likert') {
                if (!$answerOptionId) {
                    throw new \InvalidArgumentException("Likert questions require an answer_option_id.");
                }

                /** @var AssessmentAnswerOption|null $option */
                $option = $this->entityManager->getRepository(AssessmentAnswerOption::class)->find($answerOptionId);

                if (!$option || $option->getAssessmentQuestion()->getId() !== $question->getId()) {
                    throw new \InvalidArgumentException("Invalid answer option for this question.");
                }
            } elseif ($question->getIsReflection()) {
                if (empty($textAnswer)) {
                    throw new \InvalidArgumentException("Reflection questions require a text answer.");
                }
            }

            // Persistence
            $answer = new AssessmentAnswer(
                null, // UUID is auto-generated in the entity constructor
                $instance,
                $option,
                $textAnswer
            );

            $this->entityManager->persist($answer);
            $this->entityManager->flush();

            $this->logger->info('Answer successfully persisted', [
                'answer_id' => $answer->getId(),
                'instance_id' => $instance->getId()
            ]);

            return $answer;
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Validation failure in answer submission', [
                'error' => $e->getMessage(),
                'payload' => $data
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->critical('Unexpected failure in persistence layer', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    /**
     * Updates an existing answer record.
     */
    public function updateAnswer(string $id, array $data): AssessmentAnswer
    {
        try {
            $this->logger->info('Initiating answer update', ['answer_id' => $id]);

            $answer = $this->entityManager->getRepository(AssessmentAnswer::class)->find($id);

            if (!$answer) {
                throw new \InvalidArgumentException("Answer record not found for ID: $id");
            }

            // Update Likert Option
            if (isset($data['answer_option_id'])) {
                $option = $this->entityManager->getRepository(AssessmentAnswerOption::class)
                    ->find($data['answer_option_id']);

                if (!$option) {
                    throw new \InvalidArgumentException("Invalid answer option ID provided.");
                }
                $answer->setAssessmentAnswerOption($option);
            }

            // Update Reflection Text
            if (isset($data['text_answer'])) {
                $answer->setTextAnswer($data['text_answer']);
            }

            // Senior Refinement: Ensure DateTime compatibility
            $answer->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            $this->logger->info('Answer update successful', ['answer_id' => $id]);

            return $answer;

        } catch (\InvalidArgumentException $e) {
            // Business logic/validation errors are logged as warnings
            $this->logger->warning('Update validation failed', [
                'answer_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            // Unexpected system/DB errors are logged as critical
            $this->logger->critical('Unexpected error during answer update', [
                'answer_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
