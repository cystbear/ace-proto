<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Controller;

use AppBundle\Entity\Comment;
use AppBundle\Entity\Post;
use AppBundle\Events;
use AppBundle\Form\CommentType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Security\Acl\Exception\AclNotFoundException;
use Symfony\Component\Security\Acl\Exception\NoAceFoundException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Acl\Permission\BasicPermissionMap;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Controller used to manage blog contents in the public part of the site.
 *
 * @Route("/blog")
 *
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class BlogController extends Controller
{
    /**
     * @Route("/", defaults={"page": "1", "_format"="html"}, name="blog_index")
     * @Route("/rss.xml", defaults={"page": "1", "_format"="xml"}, name="blog_rss")
     * @Route("/page/{page}", defaults={"_format"="html"}, requirements={"page": "[1-9]\d*"}, name="blog_index_paginated")
     * @Method("GET")
     * @Cache(smaxage="10")
     *
     * NOTE: For standard formats, Symfony will also automatically choose the best
     * Content-Type header for the response.
     * See https://symfony.com/doc/current/quick_tour/the_controller.html#using-formats
     */
    public function indexAction($page, $_format)
    {
        $em = $this->getDoctrine()->getManager();
        $posts = $em->getRepository(Post::class)->findLatest($page);

        // Every template name also has two extensions that specify the format and
        // engine for that template.
        // See https://symfony.com/doc/current/templating.html#template-suffix
        return $this->render('blog/index.'.$_format.'.twig', ['posts' => $posts]);
    }

    /**
     * @Route("/posts/{slug}", name="blog_post")
     * @Method("GET")
     *
     * NOTE: The $post controller argument is automatically injected by Symfony
     * after performing a database query looking for a Post with the 'slug'
     * value given in the route.
     * See https://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html
     */
    public function postShowAction(Post $post)
    {
        $allComments = $post->getComments();
        $aclProvider = $this->get('security.acl.provider');

        $token = $this->get('security.token_storage')->getToken();
        $sid = UserSecurityIdentity::fromToken($token);

        $mask = (new MaskBuilder())
            ->add(MaskBuilder::MASK_OWNER)
            ->get()
        ;

        $comments = [];
        foreach ($allComments as $comment) {
            // $oid = ObjectIdentity::fromDomainObject($comment);
            $oid = new ObjectIdentity((string)$comment->getId(), Comment::class);
            try {
                $acl = $aclProvider->findAcl($oid, [$sid]);
                if ($acl->isGranted([$mask], [$sid])) {
                    $comments[] = $comment;
                }
            } catch (AclNotFoundException $e) {
            }
        }

        return $this->render('blog/post_show.html.twig', [
            'post' => $post,
            'comments' => $comments,
        ]);

//        ==========
//        ==========

//        $allComments = $post->getComments();
//
//        $token = $this->get('security.token_storage')->getToken();
//        $voter = $this->get('app.acl.voter');
//
//
//        $comments = [];
//        foreach ($allComments as $comment) {
//            if (VoterInterface::ACCESS_GRANTED === $voter->vote(
//                $token,
//                $comment, [
//                BasicPermissionMap::PERMISSION_OWNER
//            ])) {
//                $comments[] = $comment;
//            }
//        }
//
//        return $this->render('blog/post_show.html.twig', [
//            'post' => $post,
//            'comments' => $comments,
//        ]);
    }

    /**
     * @Route("/comment/{postSlug}/new", name="comment_new")
     * @Method("POST")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     * @ParamConverter("post", options={"mapping": {"postSlug": "slug"}})
     *
     * NOTE: The ParamConverter mapping is required because the route parameter
     * (postSlug) doesn't match any of the Doctrine entity properties (slug).
     * See https://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html#doctrine-converter
     */
    public function commentNewAction(Request $request, Post $post, EventDispatcherInterface $eventDispatcher)
    {
        $comment = new Comment();
        $comment->setAuthor($this->getUser());
        $post->addComment($comment);

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($comment);
            $em->flush();

            // creating the ACL
            $aclProvider = $this->get('security.acl.provider');
            $objectIdentity = ObjectIdentity::fromDomainObject($comment);
            $acl = $aclProvider->createAcl($objectIdentity);

            // retrieving the security identity of the currently logged-in user
            $tokenStorage = $this->get('security.token_storage');
            $user = $tokenStorage->getToken()->getUser();
            $securityIdentity = UserSecurityIdentity::fromAccount($user);

            // grant owner access
            $acl->insertObjectAce($securityIdentity, MaskBuilder::MASK_OWNER);
            $aclProvider->updateAcl($acl);

            // When triggering an event, you can optionally pass some information.
            // For simple applications, use the GenericEvent object provided by Symfony
            // to pass some PHP variables. For more complex applications, define your
            // own event object classes.
            // See https://symfony.com/doc/current/components/event_dispatcher/generic_event.html
            $event = new GenericEvent($comment);

            // When an event is dispatched, Symfony notifies it to all the listeners
            // and subscribers registered to it. Listeners can modify the information
            // passed in the event and they can even modify the execution flow, so
            // there's no guarantee that the rest of this controller will be executed.
            // See https://symfony.com/doc/current/components/event_dispatcher.html
            $eventDispatcher->dispatch(Events::COMMENT_CREATED, $event);

            return $this->redirectToRoute('blog_post', ['slug' => $post->getSlug()]);
        }

        return $this->render('blog/comment_form_error.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * This controller is called directly via the render() function in the
     * blog/post_show.html.twig template. That's why it's not needed to define
     * a route name for it.
     *
     * The "id" of the Post is passed in and then turned into a Post object
     * automatically by the ParamConverter.
     *
     * @param Post $post
     *
     * @return Response
     */
    public function commentFormAction(Post $post)
    {
        $form = $this->createForm(CommentType::class);

        return $this->render('blog/_comment_form.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/search", name="blog_search")
     * @Method("GET")
     *
     * @return Response|JsonResponse
     */
    public function searchAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->render('blog/search.html.twig');
        }

        $query = $request->query->get('q', '');
        $posts = $this->getDoctrine()->getRepository(Post::class)->findBySearchQuery($query);

        $results = [];
        foreach ($posts as $post) {
            $results[] = [
                'title' => htmlspecialchars($post->getTitle()),
                'summary' => htmlspecialchars($post->getSummary()),
                'url' => $this->generateUrl('blog_post', ['slug' => $post->getSlug()]),
            ];
        }

        return $this->json($results);
    }
}
