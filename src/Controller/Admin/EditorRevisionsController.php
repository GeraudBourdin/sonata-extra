<?php

namespace Partitech\SonataExtra\Controller\Admin;

use Partitech\SonataExtra\Admin\EditorAdmin;
use Partitech\SonataExtra\Repository\EditorRepository;
use Partitech\SonataExtra\Repository\EditorRevisionRepository;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

class EditorRevisionsController extends CRUDController
{
    private EditorRevisionRepository $articleRevisionRepository;

    private Pool $pool;

    private EditorRepository $editorRepository;
    private EditorRevisionRepository $editorRevisionRepository;

    #[Required]
    public function autowireDependencies(
        EditorRevisionRepository $editorRevisionRepository,
        EditorRepository $editorRepository,
        Pool $pool
    ): void {
        $this->editorRevisionRepository = $editorRevisionRepository;
        $this->editorRepository = $editorRepository;
        $this->pool = $pool;
    }

    public function applyRevisionAction(Request $request, $id = null, $childId = null): RedirectResponse
    {
        $admin = $this->admin;

        $editorRevision = $admin->getObject($childId);

        $editorAdmin = $this->pool->getAdminByAdminCode(EditorAdmin::class);
        $editor = $editorAdmin->getObject($id);

        if (!$editor || !$editorRevision) {
            $this->addFlash('error', 'Article ou révision non trouvée.');

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        // Update editor content with revision
        $editor->setContent($editorRevision->getContent());
        $editor->setTitle($editorRevision->getTitle());
        $editor->setAuthor($editorRevision->getAuthor());
        $editor->setStatus($editorRevision->getStatus());

        $this->editorRepository->save($editor, true);

        $this->addFlash('success', 'Révision appliquée avec succès.');

        return new RedirectResponse($editorAdmin->generateUrl('edit', ['id' => $editor->getId()]));
    }
}
