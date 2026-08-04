import 'package:flutter/material.dart';

import 'ai_chat_history_page.dart';
import 'ai_recipe_generator_page.dart';
import 'cooking_assistant_page.dart';

/// Landing screen for the AI Engine features (the "AI" tab).
class AiHubPage extends StatelessWidget {
  const AiHubPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('EruoFood AI')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          const _HubTile(
            icon: Icons.auto_awesome,
            title: 'AI Recipe Generator',
            subtitle: 'Draft an authentic Nigerian recipe from a brief.',
            page: AiRecipeGeneratorPage(),
          ),
          const _HubTile(
            icon: Icons.chat_bubble_outline,
            title: 'Cooking Assistant',
            subtitle: 'Chat about recipes, techniques and substitutions.',
            page: CookingAssistantPage(),
          ),
          const _HubTile(
            icon: Icons.history,
            title: 'Chat history',
            subtitle: 'Revisit your past conversations.',
            page: AiChatHistoryPage(),
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
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget page;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: Icon(icon, size: 32),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => page),
        ),
      ),
    );
  }
}
