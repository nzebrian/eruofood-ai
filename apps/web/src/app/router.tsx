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
import { ShopPage } from '@features/commerce/pages/ShopPage';
import { ProductDetailPage } from '@features/commerce/pages/ProductDetailPage';
import { ShoppingCartPage } from '@features/commerce/pages/ShoppingCartPage';
import { WishlistPage } from '@features/commerce/pages/WishlistPage';
import { WalletPage } from '@features/payments/pages/WalletPage';
import { TransactionsPage } from '@features/payments/pages/TransactionsPage';
import { PaymentSettingsPage } from '@features/payments/pages/PaymentSettingsPage';
import { NotificationCentrePage } from '@features/notifications/pages/NotificationCentrePage';
import { MessagesPage } from '@features/notifications/pages/MessagesPage';
import { NotificationSettingsPage } from '@features/notifications/pages/NotificationSettingsPage';
import { AnalyticsDashboardPage } from '@features/analytics/pages/AnalyticsDashboardPage';
import { ReportsPage } from '@features/analytics/pages/ReportsPage';
import { AdminDashboardPage } from '@features/admin/pages/AdminDashboardPage';
import { UserManagementPage } from '@features/admin/pages/UserManagementPage';
import { ContentManagerPage } from '@features/admin/pages/ContentManagerPage';
import { SystemConfigPage } from '@features/admin/pages/SystemConfigPage';
import { SupportDashboardPage } from '@features/admin/pages/SupportDashboardPage';
import { SearchPage } from '@features/search/pages/SearchPage';
import { SupportPortalPage } from '@features/support/pages/SupportPortalPage';
import { AgentWorkspacePage } from '@features/support/pages/AgentWorkspacePage';
import { KnowledgeBasePage } from '@features/support/pages/KnowledgeBasePage';
import { CrmDashboardPage } from '@features/support/pages/CrmDashboardPage';
import { SubjectReviewsPage } from '@features/reviews/pages/SubjectReviewsPage';
import { ModerationQueuePage } from '@features/reviews/pages/ModerationQueuePage';
import { LoyaltyPage } from '@features/loyalty/pages/LoyaltyPage';
import { DeveloperPortalPage } from '@features/developer/pages/DeveloperPortalPage';
import { ProtectedRoute } from './ProtectedRoute';

export const router = createBrowserRouter([
  // Public catalogue
  { path: '/', element: <FoodCataloguePage /> },
  { path: '/foods/:slug', element: <FoodDetailPage /> },
  { path: '/recipes', element: <RecipesPage /> },
  { path: '/recipes/:slug', element: <RecipeDetailPage /> },

  // Search, Discovery & Recommendation (public)
  { path: '/search', element: <SearchPage /> },

  // Customer Support, Helpdesk & CRM
  { path: '/help', element: <KnowledgeBasePage /> },
  {
    path: '/support',
    element: (
      <ProtectedRoute>
        <SupportPortalPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/support/agent',
    element: (
      <ProtectedRoute>
        <AgentWorkspacePage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/support/crm',
    element: (
      <ProtectedRoute>
        <CrmDashboardPage />
      </ProtectedRoute>
    ),
  },

  // Reviews & Ratings
  { path: '/reviews', element: <SubjectReviewsPage /> },
  {
    path: '/reviews/moderation',
    element: (
      <ProtectedRoute>
        <ModerationQueuePage />
      </ProtectedRoute>
    ),
  },

  // Loyalty, Rewards & Referrals
  {
    path: '/rewards',
    element: (
      <ProtectedRoute>
        <LoyaltyPage />
      </ProtectedRoute>
    ),
  },

  // Developer Platform (Public API)
  {
    path: '/developer',
    element: (
      <ProtectedRoute>
        <DeveloperPortalPage />
      </ProtectedRoute>
    ),
  },

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

  // Marketplace, Grocery & Commerce (public shop; cart/wishlist need auth)
  { path: '/shop', element: <ShopPage /> },
  { path: '/shop/:slug', element: <ProductDetailPage /> },
  {
    path: '/shop-cart',
    element: (
      <ProtectedRoute>
        <ShoppingCartPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/wishlist',
    element: (
      <ProtectedRoute>
        <WishlistPage />
      </ProtectedRoute>
    ),
  },

  // Payments, Wallet & Financial Services
  {
    path: '/wallet-account',
    element: (
      <ProtectedRoute>
        <WalletPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/transactions',
    element: (
      <ProtectedRoute>
        <TransactionsPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/payment-settings',
    element: (
      <ProtectedRoute>
        <PaymentSettingsPage />
      </ProtectedRoute>
    ),
  },

  // Notifications, Messaging & Real-Time Communication
  {
    path: '/notifications',
    element: (
      <ProtectedRoute>
        <NotificationCentrePage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/messages',
    element: (
      <ProtectedRoute>
        <MessagesPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/notification-settings',
    element: (
      <ProtectedRoute>
        <NotificationSettingsPage />
      </ProtectedRoute>
    ),
  },

  // Analytics, Business Intelligence & Reporting (admin)
  {
    path: '/analytics',
    element: (
      <ProtectedRoute>
        <AnalyticsDashboardPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/reports',
    element: (
      <ProtectedRoute>
        <ReportsPage />
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

  // Platform Administration, CMS & Operations
  {
    path: '/admin',
    element: (
      <ProtectedRoute>
        <AdminDashboardPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/admin/users',
    element: (
      <ProtectedRoute>
        <UserManagementPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/admin/content',
    element: (
      <ProtectedRoute>
        <ContentManagerPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/admin/config',
    element: (
      <ProtectedRoute>
        <SystemConfigPage />
      </ProtectedRoute>
    ),
  },
  {
    path: '/admin/support',
    element: (
      <ProtectedRoute>
        <SupportDashboardPage />
      </ProtectedRoute>
    ),
  },
]);
