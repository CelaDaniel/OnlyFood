<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Recipe;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Ingredients;
use App\Entity\IngredientQuantity;
use Symfony\Component\Serializer\SerializerInterface;

class RecipeController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/recipe", name="app_recipe")
     */
    public function index(): Response
    {
        return $this->render('recipe/index.html.twig', [
            'controller_name' => 'RecipeController',
        ]);
    }

    /**
     * @Route("/api/createRecipe", name="app_recipe_create")
     */
    public function createRecipe(Request $request, SerializerInterface $serializer)
    {
        $user = $this->getUser();

        $recipe = new Recipe();
        $recipe->setUserId($user);
        $this->updateDatabase($recipe);

        $jsonContent = $serializer->serialize($recipe, 'json', ['groups' => 'recipe_overview']);

        return new Response($jsonContent, Response::HTTP_OK);
    }

    /**
     * @Route("/api/recipe/{id}", name="app_recipe_show", methods={"GET"})
     */
    public function show(Request $request, SerializerInterface $serializer)
    {
        $recipe = $this->entityManager->getRepository(Recipe::class)->findOneBy(['id' => $request->get('id')]);
        $user = $this->getUser();
        $isUserRecipe = false;

        if($recipe){
            $recipeUser = $recipe->getUserId();

            if ($recipeUser == $user) {
                $isUserRecipe = true;
            }
        }
        
        $jsonContent = $serializer->serialize($recipe, 'json', ['groups' => 'recipe_overview']);

        $newResponse = array(
            'recipe' => $jsonContent,
            'isUserRecipe' => $isUserRecipe
        );

        return new JsonResponse($newResponse, Response::HTTP_OK);
    }


    /** @param Request
     * @return Response
     * @Route("/api/recipe/{id}/uploadRecipeImage" , name="api_upload_profile_picture", methods={"POST"})
     */
    public function uploadRecipeImage(Request $request, SerializerInterface $serializer): Response
    {
        $user = $this->getUser();
        $userName = $this->getUser()->getUserIdentifier();
        $path = $request->files->get('file');
        $fileName = $userName . '.' . $path->guessExtension();
        $recipe = $this->entityManager->getRepository(Recipe::class)->findOneBy(['id' => $request->get('id')]);

        if($path) {
            $recipe->setImageFile($path);
            $recipe->setImageName($fileName);
            $this->updateDatabase($user);
        }

        $jsonContent = $serializer->serialize($recipe, 'json', ['groups' => 'recipe_overview']);

        return new Response($jsonContent, Response::HTTP_OK);
    }

    /** @param Request
     * @return Response
     * @Route("/api/recipe/{id}/updateRecipe" , name="api_update_recipe", methods={"POST"})
     */
    public function updateRecipe(Request $request, SerializerInterface $serializer): Response
    {
        $user = $this->getUser();
        $content = json_decode($request->getContent(), true);

        $recipe = $this->entityManager->getRepository(Recipe::class)->findOneBy(['id' => $request->get('id')]);

        if (!$content['name']) { 
            throw new \Exception('Recipe name is required');
        } else {
            $recipe->setName($content['name']);
        }

        if (!$content['method']) { 
            throw new \Exception('Method is required');
        } else {
            $recipe->setMethod($content['method']);
        }

        if (!$content['difficulty']) { 
            throw new \Exception('Difficulty is required');
        } else {
            $recipe->setDifficulty($content['difficulty']);
        }

        $recipe->setPortion($content['portion']);
        $recipe->setTags($content['tags']);
        $recipe->setPrepTime($content['prepTime']);


        if (!$content['ingredients']) { 
            throw new \Exception('Ingredients are required');
        } else {
            foreach ($content['ingredients'] as $ingredient) {
                $ingredientRepo = $this->entityManager->getRepository(Ingredients::class);
                $ingredientEntity = $ingredientRepo->findOneBy(['id' => $ingredient['id']]);
                if (!$ingredientEntity) {
                    $ingredientEntity = new Ingredients();
                    $this->setIngredients($ingredientEntity, $ingredient);
                    $ingredientEntity->addRecipe($recipe);
                } else {
                    $this->setIngredients($ingredientEntity, $ingredient);
                }

                $this->updateDatabase($ingredientEntity);
            }
        }

        $this->updateDatabase($recipe);

        $jsonContent = $serializer->serialize($recipe, 'json', ['groups' => 'recipe_overview']);

        return new Response($jsonContent, Response::HTTP_OK);
    }

    
    /**
     * @Route("/api/editRecipe/{id}", name="app_recipe_edit")
     */
    public function editRecipe(Request $request, SerializerInterface $serializer, $id)
    {
        $user = $this->getUser();
        $recipe = $this->entityManager->getRepository(Recipe::class)->findOneBy(['id' => $id]);
        $jsonContent = $serializer->serialize($recipe, 'json', ['groups' => 'recipe_overview']);

        return new Response($jsonContent, Response::HTTP_OK);
    }


    /** @param Request
     * @return Response
     * @Route("/api/recipe/{id}/cancelRecipe" , name="api_delete_recipe", methods={"DELETE"})
     */
    public function cancelRecipe(Request $request, SerializerInterface $serializer): Response
    {
        $recipe = $this->entityManager->getRepository(Recipe::class);
        $recipeId = $recipe->findOneBy(['id' => $request->get('id')]);
        $ingredients = $this->entityManager->getRepository(Ingredients::class)->getRecipeId($recipeId);

        if ($ingredients) {
            foreach ($ingredients as $ingredient) {
                $this->entityManager->remove($ingredient);
            }
        }

        $this->entityManager->remove($recipeId);
        $this->entityManager->flush();
        $jsonContent = $serializer->serialize($recipeId, 'json', ['groups' => 'recipe_overview']);

        return new Response($jsonContent, Response::HTTP_OK);
    }


    private function setIngredients($ingredientEntity, $ingredient) 
    {
        if ($ingredient['name']) { 
            $ingredientEntity->setName($ingredient['name']);
        }  else {
            throw new \Exception('Ingredient Name is required');
        }
        if ($ingredient['quantity']) { 
            if(is_numeric($ingredient['quantity'])) {
                $ingredientEntity->setQuantity($ingredient['quantity']);
            } else {
                throw new \Exception('Quantity has to be an integer');
            }
            $ingredientEntity->setQuantity($ingredient['quantity']);
        } else {
            throw new \Exception('Quantity is required');
        }

        $ingredientEntity->setUnit($ingredient['unit']);
    }

    public function updateDatabase($object)
    {
        $this->entityManager->persist($object);
        $this->entityManager->flush();
    }
}
