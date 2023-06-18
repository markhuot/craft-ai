<?php

namespace markhuot\craftai\db;

use Illuminate\Support\Collection;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\GenerateEmbeddings;
use markhuot\craftai\models\Backend;
use markhuot\craftai\search\Search;

class AskQuery
{
    protected ?string $prompt;

    public function __construct(
        protected Search $search,
    ) {
    }

    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * @return array{string|null, Collection}
     */
    public function answer(): array
    {
        if (empty($this->prompt)) {
            return [null, collect()];
        }

        $documents = $this->getMatchingDocuments();

        $context = $documents->pluck('_source._keywords')->map(fn ($k) => 'the context is '.$k)->join("\n");
        $prompt = implode("\n\n", [
            'Given the following context attempt to answer the question below. If you do not know the answer with certainity respond with "I don\'t know".',
            "Context\n{$context}",
            "Question: {$this->prompt}",
            'Answer: ',
        ]);

        $response = Backend::for(Completion::class)->completeText($prompt);

        return [$response->text, $documents];
    }

    protected function getMatchingDocuments(): Collection
    {
        $vectors = Backend::for(GenerateEmbeddings::class)
            ->generateEmbeddings($this->prompt)->vectors;

        [$hits] = $this->search->knnSearch(
            vectors: $vectors,
            limit: 1,
        );

        return $hits;
    }
}
