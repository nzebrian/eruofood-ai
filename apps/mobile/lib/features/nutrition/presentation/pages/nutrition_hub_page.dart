import 'package:flutter/material.dart';

import 'health_profile_page.dart';
import 'meal_planner_page.dart';
import 'nutrition_dashboard_page.dart';
import 'progress_tracker_page.dart';

/// Landing screen for the Nutrition, Health & Personalisation features.
class NutritionHubPage extends StatelessWidget {
  const NutritionHubPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Nutrition & Health')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: const <Widget>[
          _HubTile(
            icon: Icons.dashboard_outlined,
            title: 'Dashboard',
            subtitle: 'Your calorie & macro targets and today’s intake.',
            page: NutritionDashboardPage(),
            scaffold: true,
          ),
          _HubTile(
            icon: Icons.person_outline,
            title: 'Health profile',
            subtitle: 'Weight, height, activity, goals and restrictions.',
            page: HealthProfilePage(),
          ),
          _HubTile(
            icon: Icons.calendar_month_outlined,
            title: 'Meal planner',
            subtitle: 'Plans, shopping and AI meal ideas.',
            page: MealPlannerPage(),
          ),
          _HubTile(
            icon: Icons.trending_up,
            title: 'Progress',
            subtitle: 'Log weight and get weekly insights.',
            page: ProgressTrackerPage(),
          ),
        ],
      ),
    );
  }
}

class _HubTile extends StatelessWidget {
  const _HubTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.page,
    this.scaffold = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget page;
  final bool scaffold;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: Icon(icon, size: 32),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => scaffold
                ? Scaffold(appBar: AppBar(title: Text(title)), body: page)
                : page,
          ),
        ),
      ),
    );
  }
}
