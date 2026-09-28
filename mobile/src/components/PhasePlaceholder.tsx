import { StyleSheet, Text, View } from 'react-native';

type PhasePlaceholderProps = {
  title: string;
};

export function PhasePlaceholder({ title }: PhasePlaceholderProps) {
  return (
    <View style={styles.container}>
      <Text style={styles.eyebrow}>SkinSyntaxVN Mobile</Text>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.description}>Chức năng sẽ được phát triển ở phase sau.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    padding: 24,
    backgroundColor: '#F7FAF8',
  },
  eyebrow: {
    marginBottom: 10,
    color: '#1F6B45',
    fontSize: 13,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  title: {
    color: '#10251A',
    fontSize: 26,
    fontWeight: '800',
  },
  description: {
    marginTop: 12,
    color: '#56675D',
    fontSize: 16,
    lineHeight: 23,
  },
});
