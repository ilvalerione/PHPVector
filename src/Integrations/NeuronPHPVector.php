<?php

namespace PHPVector\Integrations;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document as NeuronDocument;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\StaticConstructor;
use PHPVector\Document;
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
                text: $document->text,
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
    }

    /**
     * @param array<float> $embedding
     * @return iterable<Document>
     */
    public function similaritySearch(array $embedding): iterable
    {
        $results = $this->database->vectorSearch(
            vector: $embedding,
            k: $this->topK,
        );

        return array_map(function (Document $document): NeuronDocument {
            $item = new NeuronDocument($document->text);
            $item->id = $document->id;
            $item->embedding = $document->vector;
            $item->metadata = $document->metadata;
            return $item;
        }, $results);
    }
}