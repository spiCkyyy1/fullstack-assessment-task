# Solution - Hassam Waheed

## Task Completed
Backend/Full-Stack

## Time Spent
75 minutes

## Approach
Approach has been simple. Going through the readme file first and following the instructions. I am quiet familiar with this type of Architecture design, 
and also business logic as I have previously worked within this industry. So, I wasn't hard for me to understand the project structure, or assessment business logic.
First thing first, I understood the Database and using the .sql file and going through the models. Once done, I wrote my thoughts within the solution.md file.

## PHASE 1: Entity Relationship Diagram

![Assessment System ERD](./erd-diagram.png)
The assessment system is built on a decoupled architecture that separates the reusable assessment templates from the individual user sessions and response data.
Below is the breakdown of the entities and how they relate to one another:

1. assessment (The Template)
   - Description: This is the core "blueprint" for an assessment, such as in this example, "Element is 1.1".
   - Keys: id is the Primary Key.
   - Cardinality: It has a Many-to-Many (N:M) relationship with assessment_questions via the assessments_questions pivot table.

2. assessment_questions (The Question Bank)
   - Description: Holds the actual question text and defines the type (e.g., Likert or text-based reflection).
   - Keys: id is the Primary Key.
   - Cardinality: Linked to assessments via the assessments_questions pivot table. This allows one question to be used in multiple assessments and one assessment to have many questions.

3. assessment_answer_options (Multiple Choice Definitions)
   - Description: Stores the predefined 1-5 scale options for Likert questions.
   - Keys: id (Primary Key) and assessment_question_id as the Foreign Key coming from assessment_questions table.
   - Cardinality: Many-to-One (N:1) relationship with assessment_questions (one question of type Likert can have several answer options. Option number defined the sorting.).

4. assessment_session (The User's Journey)
   - Description: Represents a specific user's engagement with an assessment template.
   - Keys: id (Primary Key) and assessment_id as the Foreign Key coming from assessment table.
   - Cardinality: Many-to-One (N:1) relationship with assessment. While a user usually has one session per assessment, the assessment itself can have many sessions with different users.

5. assessment_instance (The Specific Attempt)
   - Description: Tracks a specific "run" of an assessment within a session, including timestamps for completion.
   - Keys: id (Primary Key) and session_id as the Foreign Key coming from assessment_session table.
   - Relationship: Many-to-One (N:1) relationship with assessment_session.

6. assessment_answers (The Captured Data)
   - Description: The actual responses submitted. It stores either a reference to a preset option for Likert questions or raw text for reflections.
   - Keys: id (Primary Key), assessment_instance_id (Foreign Key) coming from assessment_instance, and assessment_answer_option_id (Foreign Key) coming from assessment_answer_options table.
   - Cardinality: Many-to-One (N:1) relationship with assessment_instance. One instance will contain many individual answers.

## Phase 2: Review Results Calculation

### Scoring Algorithm Analysis
I have performed a manual review of the `AssessmentService::getProgressAndScore()` logic to verify the 53.85% result returned by the API for instance `d1111111...`.

**The Logic:**
The system uses a normalization formula: `(total_score - answered_questions) / (max_score - answered_questions) * 100`.

### Review & Refinements

While the math is solid, I identified several areas for hardening and optimization to make this service production-ready:

#### 1. Optimization: N+1 Query Problem
During the review, I noticed that `findAssessmentAnswerOptionsByQuestion()` is called inside a `foreach` loop. This introduces a classic N+1 performance bottleneck where the number of database queries grows linearly with the number of questions.
* **Observation**: In a larger assessment, this would cause significant latency.
* **Recommendation**: In a real production environment, I would refactor the repository to eager-load all answer options in a single query indexed by question ID to keep the database overhead constant.

#### 2. Hardened Error Handling
I implemented the following defensive programming measures:
* **Null Safety**: Added guards to verify that both the `Session` and `Assessment` objects exist before processing. This prevents the API from throwing a 500 error if it encounters orphaned instance data.
* **Division-by-Zero Guards**: I verified the logic for `completion_percentage` and the normalized `scorePercentage`. If a template is ever created with zero questions or zero-weight options, the service returns 0 rather than crashing.
* **Rounding Consistency**: Ensured all percentages are rounded to 2 decimal places at the service level to maintain API contract reliability.