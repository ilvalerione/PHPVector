<?php

namespace PHPVector\Integrations;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document as NeuronDocument;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\StaticConstructor;
use PHPVector\Document;
use PHPVector\SearchResult;
use PHPVector\VectorDatabase;

class NeuronPHPVector implements VectorStoreInterface
{
    use StaticConstructor;
    
    public function __construct(
        protected VectorDatabase $database,
        protected int $topK = 5,
    ){
    }

    public function addDocument(NeuronDocument $document): VectorStoreInterface
    {
        $this->addDocuments([$document]);
        return $this;
    }

    /**
     * @param NeuronDocument[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->database->addDocuments(
            array_map(fn (NeuronDocument $document): Document => new Document(
                id: $document->id,
                vector: $document->embedding,
                text: $document->content,
                metadata: $document->metadata,
            ), $documents)
        );

        return $this;
    }

    /**
     * @throws VectorStoreException
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        throw new VectorStoreException('Deletion not supported.');
    }

    /**
     * @throws VectorStoreException
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->deleteBy($sourceType, $sourceName);
        return $this;
    }

    /**
     * @param array<float> $embedding
     * @return iterable<NeuronDocument>
     */
    public function similaritySearch(array $embedding): iterable
    {
        $results = $this->database->vectorSearch(
            vector: $embedding,
            k: $this->topK,
        );

        return array_map(function (SearchResult $result): NeuronDocument {
            $document = new NeuronDocument($result->document->text);
            $document->id = $result->document->id;
            $document->embedding = $result->document->vector;
            $document->metadata = $result->document->metadata;
            $document->score = $result->score;
            return $document;
        }, $results);
    }
}