import { createBrowserRouter } from 'react-router-dom';
import { LoginPage } from '@features/auth/pages/LoginPage';
import { RegisterPage } from '@features/auth/pages/RegisterPage';
import { ForgotPasswordPage } from '@features/auth/pages/ForgotPasswordPage';
import { ResetPasswordPage } from '@features/auth/pages/ResetPasswordPage';
import { ProfilePage } from '@features/profile/pages/ProfilePage';
import { FoodCataloguePage } from '@features/catalog/pages/FoodCataloguePage';
import { FoodDetailPage } from '@features/catalog/pages/FoodDetailPage';
import { RecipesPage } from '@features/catalog/pages/RecipesPage';
import { RecipeDetailPage } from '@features/catalog/pages/RecipeDetailPage';
import { FavouritesPage } from '@features/catalog/pages/FavouritesPage';
import { AdminFoodsPage } from '@features/catalog/pages/admin/AdminFoodsPage';
import { AiRecipeGeneratorPage } from '@features/ai/pages/AiRecipeGeneratorPage';
import { CookingAssistantPage } from '@features/ai/pages/CookingAssistantPage';
import { ChatHistoryPage } from '@features/ai/pages/ChatHistoryPage';
import { AiSettingsPage } from '@features/ai/pages/AiSettingsPage';
import { NutritionDashboardPage } from '@features/nutrition/pages/NutritionDashboardPage';
import { HealthProfilePage } from '@features/nutrition/pages/HealthProfilePage';
import { MealPlannerPage } from '@features/nutrition/pages/MealPlannerPage';
import { ProgressDashboardPage } from '@features/nutrition/pages/ProgressDashboardPage';
import { VendorsPage } from '@features/marketplace/pages/VendorsPage';
import { VendorStorefrontPage } from '@features/marketplace/pages/VendorStorefrontPage';
import { CartPage } from '@features/marketplace/pages/CartPage';
import { OrdersPage } from '@features/marketplace/pages/OrdersPage';
import { VendorDashboardPage } from '@features/marketplace/pages/VendorDashboardPage';
import { ProtectedRoute } from './ProtectedRoute';

export const router = createBrowserRouter([
  // Public catalogue
  { path: '/', element: <FoodCataloguePage /> },
  { path: '/foods/:slug', element: <FoodDetailPage /> },
  { path: '/recipes', element: <RecipesPage /> },
  { path: '/recipes/:slug', element: <RecipeDetailPage /> },

  // Auth
  { path: '/login', element: <LoginPage /> },
  { path: '/register', element: <RegisterPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },

  // Authenticated
  {
    path: '/favourites',
    element: (
      <ProtectedRoute>
        <FavouritesPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/account',
    element: (
      <ProtectedRoute>
        <ProfilePage />
      </ProtectedRoute>
    ),
  },

  // AI Engine
  {
    path: '/ai/recipe-generator',
    element: (
      <ProtectedRoute>
        <AiRecipeGeneratorPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/ai/assistant',
    element: (
      <ProtectedRoute>
        <CookingAssistantPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/ai/history',
    element: (
      <ProtectedRoute>
        <ChatHistoryPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/ai/settings',
    element: (
      <ProtectedRoute>
        <AiSettingsPage />
      </ProtectedRoute>
    ),
  },

  // Marketplace
  { path: '/vendors', element: <VendorsPage /> },
  { path: '/vendors/:slug', element: <VendorStorefrontPage /> },
  {
    path: '/cart',
    element: (
      <ProtectedRoute>
        <CartPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/orders',
    element: (
      <ProtectedRoute>
        <OrdersPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/vendor-dashboard',
    element: (
      <ProtectedRoute>
        <VendorDashboardPage />
      </ProtectedRoute>
    ),
  },

  // Nutrition, Health & Personalisation
  {
    path: '/nutrition',
    element: (
      <ProtectedRoute>
        <NutritionDashboardPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/nutrition/profile',
    element: (
      <ProtectedRoute>
        <HealthProfilePage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/nutrition/meal-planner',
    element: (
      <ProtectedRoute>
        <MealPlannerPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/nutrition/progress',
    element: (
      <ProtectedRoute>
        <ProgressDashboardPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/admin/foods',
    element: (
      <ProtectedRoute>
        <AdminFoodsPage />
      </ProtectedRoute>
    ),
  },
]);
