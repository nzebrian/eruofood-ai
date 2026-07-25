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
  {
    path: '/admin/foods',
    element: (
      <ProtectedRoute>
        <AdminFoodsPage />
      </ProtectedRoute>
    ),
  },
]);
