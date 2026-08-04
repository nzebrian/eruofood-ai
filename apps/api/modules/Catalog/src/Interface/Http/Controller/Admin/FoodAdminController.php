<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller\Admin;

use EruoFood\Catalog\Application\Input\FoodInput;
use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\FoodService;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Catalog\Interface\Http\Request\FoodRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin food management (create/update/publish/media). */
final readonly class FoodAdminController
{
    use RespondsWithData;

    public function __construct(
        private FoodService $foods,
        private CatalogPresenter $presenter,
    ) {
    }

    public function store(FoodRequest $request): JsonResponse
    {
        $food = $this->foods->create(FoodInput::fromArray($request->validated()));

        return $this->data($this->presenter->food($food), 201);
    }

    public function update(FoodRequest $request, string $id): JsonResponse
    {
        $food = $this->foods->update($id, FoodInput::fromArray($request->validated()));

        return $this->data($this->presenter->food($food));
    }

    public function publish(string $id): JsonResponse
    {
        return $this->data($this->presenter->food($this->foods->publish($id)));
    }

    public function archive(string $id): JsonResponse
    {
        return $this->data($this->presenter->food($this->foods->archive($id)));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->foods->delete($id);

        return new JsonResponse(null, 204);
    }

    public function uploadImage(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $request->file('image');

        $food = $this->foods->addImage($id, (string) $file->get(), $file->extension() ?: 'jpg');

        return $this->data($this->presenter->food($food), 201);
    }

    public function deleteImage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['path' => ['required', 'string']]);

        return $this->data($this->presenter->food($this->foods->removeImage($id, $validated['path'])));
    }

    public function setVideo(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['video_url' => ['nullable', 'url', 'max:2048']]);

        return $this->data($this->presenter->food($this->foods->setVideoUrl($id, $validated['video_url'] ?? null)));
    }
}
